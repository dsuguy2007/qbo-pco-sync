<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/PcoClient.php';
require_once __DIR__ . '/../src/QboClient.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Mailer.php';

$webhookSecretValid = false;
$incomingSecret     = $_GET['webhook_secret'] ?? null;
if ($incomingSecret !== null && !empty($config['webhook_secrets']) && is_array($config['webhook_secrets'])) {
    foreach ($config['webhook_secrets'] as $s) {
        if (!empty($s) && hash_equals((string)$s, (string)$incomingSecret)) {
            $webhookSecretValid = true;
            break;
        }
    }
}

if (!$webhookSecretValid) {
    Auth::requireLogin();
}

function acquire_lock(string $name, int $ttlSeconds = 900): bool
{
    $lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qbo_pco_' . $name . '.lock';
    if (file_exists($lockFile)) {
        $age = time() - (int)filemtime($lockFile);
        if ($age < $ttlSeconds) {
            return false;
        }
    }
    @touch($lockFile);
    register_shutdown_function(static function () use ($lockFile) {
        if (file_exists($lockFile)) {
            @unlink($lockFile);
        }
    });
    return true;
}

function map_payment_method_name(?string $raw): ?string
{
    if ($raw === null) { return null; }
    $m = strtolower(trim($raw));
    if ($m === '') { return null; }
    if (in_array($m, ['card', 'credit_card', 'credit card'], true)) { return 'Credit Card'; }
    if ($m === 'ach') { return 'ACH'; }
    if ($m === 'eft') { return 'EFT'; }
    if ($m === 'cash') { return 'cash'; }
    if (in_array($m, ['check', 'cheque'], true)) { return 'check'; }
    return null;
}

function get_setting(PDO $pdo, string $key): ?string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM sync_settings WHERE setting_key = :key ORDER BY id DESC LIMIT 1');
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row && isset($row['setting_value']) ? (string)$row['setting_value'] : null;
}

function set_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare('DELETE FROM sync_settings WHERE setting_key = :key');
    $stmt->execute([':key' => $key]);
    $stmt = $pdo->prepare('INSERT INTO sync_settings (setting_key, setting_value) VALUES (:key, :value)');
    $stmt->execute([':key' => $key, ':value' => $value]);
}

function acquire_db_lock(PDO $pdo, string $name, int $ttlSeconds = 900): ?string
{
    $owner = uniqid($name . '_', true);
    $key   = 'lock_' . $name;

    $stmt = $pdo->prepare('INSERT IGNORE INTO sync_settings (setting_key, setting_value) VALUES (:key, \'\')');
    $stmt->execute([':key' => $key]);

    $stmt = $pdo->prepare(
        'UPDATE sync_settings
            SET setting_value = :owner_set, updated_at = NOW()
          WHERE setting_key = :key
            AND (TIMESTAMPDIFF(SECOND, updated_at, NOW()) > :ttl OR setting_value = :empty OR setting_value = :owner_match)'
    );
    $stmt->execute([
        ':owner_set'   => $owner,
        ':key'         => $key,
        ':ttl'         => $ttlSeconds,
        ':empty'       => '',
        ':owner_match' => $owner,
    ]);

    if ($stmt->rowCount() === 1) {
        return $owner;
    }
    return null;
}

function release_db_lock(PDO $pdo, string $name, string $owner): void
{
    $key = 'lock_' . $name;
    $stmt = $pdo->prepare(
        'UPDATE sync_settings SET setting_value = \'\', updated_at = NOW()
         WHERE setting_key = :key AND setting_value = :owner'
    );
    $stmt->execute([':key' => $key, ':owner' => $owner]);
}

function has_synced_deposit(PDO $pdo, string $type, string $fingerprint): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM synced_deposits WHERE type = :t AND fingerprint = :fp LIMIT 1');
    $stmt->execute([':t' => $type, ':fp' => $fingerprint]);
    return (bool)$stmt->fetchColumn();
}

function mark_synced_deposit(PDO $pdo, string $type, string $fingerprint): void
{
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO synced_deposits (type, fingerprint, created_at) VALUES (:t, :fp, :created_at)'
    );
    $stmt->execute([
        ':t'  => $type,
        ':fp' => $fingerprint,
        ':created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
    ]);
}

function last_refund_total_cents(PDO $pdo, string $regId): int
{
    try {
        $stmt = $pdo->prepare('SELECT fingerprint FROM synced_deposits WHERE type = :t AND fingerprint LIKE :fp');
        $stmt->execute([
            ':t'  => 'registrations_refund',
            ':fp' => 'reg_refund|' . $regId . '|%',
        ]);
        $max = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $fp = $row['fingerprint'] ?? '';
            $parts = explode('|', $fp);
            if (count($parts) === 3) {
                $amt = (int)$parts[2];
                if ($amt > $max) {
                    $max = $amt;
                }
            }
        }
        return $max;
    } catch (Throwable $e) {
        return 0;
    }
}

function get_display_timezone(PDO $pdo): DateTimeZone
{
    $tz = null;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM sync_settings WHERE setting_key = 'display_timezone' ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        if ($val) {
            $tz = new DateTimeZone((string)$val);
        }
    } catch (Throwable $e) {
        $tz = null;
    }

    return $tz ?? new DateTimeZone('UTC');
}

function fmt_dt(DateTimeInterface $dt, DateTimeZone $tz): string
{
    return $dt->setTimezone($tz)->format('Y-m-d h:i A T');
}

function parse_money_to_cents($val): int
{
    if ($val === null) {
        return 0;
    }
    if (is_numeric($val)) {
        return (int)round(((float)$val) * 100);
    }
    if (is_string($val)) {
        $clean = preg_replace('/[^0-9.\\-]/', '', $val);
        if ($clean === '' || $clean === '-' || $clean === null) {
            return 0;
        }
        return (int)round(((float)$clean) * 100);
    }
    return 0;
}

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

try {
    $db  = Db::getInstance($config['db']);
    $pdo = $db->getConnection();
    $lockOwner = acquire_db_lock($pdo, 'registrations_sync', 900);
    if ($lockOwner === null) {
        http_response_code(429);
        echo '<h1>Registrations sync busy</h1><p>Another registrations sync is running. Please try again shortly.</p>';
        exit;
    }
    register_shutdown_function(static function () use ($pdo, $lockOwner) {
        try { release_db_lock($pdo, 'registrations_sync', $lockOwner); } catch (Throwable $e) { /* ignore */ }
    });
    $displayTz = get_display_timezone($pdo);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Database error</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

try {
    $pco = new PcoClient($config);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>PCO error</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

try {
    $qbo = new QboClient($pdo, $config);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>QBO error</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

// ---------------------------------------------------------------------------
// Window handling
// ---------------------------------------------------------------------------

$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$backfillDays  = isset($_GET['backfill_days']) ? max(1, min(90, (int)$_GET['backfill_days'])) : 7;
$defaultSince  = $nowUtc->sub(new DateInterval('P' . $backfillDays . 'D'));
$forceRefunds  = isset($_GET['force_refunds']) && $_GET['force_refunds'] === '1';
$sinceUtc = $defaultSince; // Default to N-day lookback unless explicitly overridden.

// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------

$regDepositName  = get_setting($pdo, 'reg_deposit_bank_account_name') ?? ($config['qbo']['stripe_deposit_bank'] ?? '');
$regIncomeName   = get_setting($pdo, 'reg_income_account_name') ?? ($config['qbo']['stripe_income_account'] ?? '');
$regClassName    = get_setting($pdo, 'reg_class_name') ?? '';
$regLocationName = get_setting($pdo, 'reg_location_name') ?? '';
$feeAccountName  = get_setting($pdo, 'stripe_fee_account_name') ?? ($config['qbo']['stripe_fee_account'] ?? '');

$notificationEmail = get_setting($pdo, 'notification_email');

$errors = [];

try {
    $depositAccount = $qbo->getAccountByName($regDepositName, false);
    $incomeAccount  = $qbo->getAccountByName($regIncomeName, true);
    $feeAccount     = $qbo->getAccountByName($feeAccountName, true);
    if (!$depositAccount) { $errors[] = "Deposit account not found: {$regDepositName}"; }
    if (!$incomeAccount)  { $errors[] = "Income account not found: {$regIncomeName}"; }
    if (!$feeAccount)     { $errors[] = "Fee account not found: {$feeAccountName}"; }
} catch (Throwable $e) {
    $errors[] = 'Error looking up QBO accounts: ' . $e->getMessage();
}

$classRef = null;
if ($regClassName !== '') {
    try {
        $classObj = $qbo->getClassByName($regClassName);
        if ($classObj) {
            $classRef = ['value' => (string)$classObj['Id'], 'name' => $classObj['Name'] ?? $regClassName];
        } else {
            $errors[] = "Class not found: {$regClassName}";
        }
    } catch (Throwable $e) {
        $errors[] = 'Error looking up Class: ' . $e->getMessage();
    }
}

$deptRef = null;
if ($regLocationName !== '') {
    try {
        $deptObj = $qbo->getDepartmentByName($regLocationName);
        if ($deptObj) {
            $deptRef = ['value' => (string)$deptObj['Id'], 'name' => $deptObj['Name'] ?? $regLocationName];
        } else {
            $errors[] = "Location/Department not found: {$regLocationName}";
        }
    } catch (Throwable $e) {
        $errors[] = 'Error looking up Location: ' . $e->getMessage();
    }
}

if (!empty($errors)) {
    echo '<h1>Registrations sync</h1><div><strong>Config errors:</strong><ul>';
    foreach ($errors as $err) {
        echo '<li>' . htmlspecialchars($err, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    echo '</ul></div><p><a href="index.php">&larr; Back to dashboard</a></p>';
    exit;
}

// ---------------------------------------------------------------------------
// Fetch payments
// ---------------------------------------------------------------------------

try {
    $resp     = $pco->listRegistrationPayments(['include' => 'event,person,registration', 'per_page' => 100]);
    $payments = $resp['data'] ?? [];
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>PCO error</h1><pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

// Grab registration details (needed for registration date / person name in memos)
$registrationLookup = [];
$registrationIds    = [];
$registrationMeta   = [];
foreach ($payments as $pay) {
    $regId = $pay['relationships']['registration']['data']['id'] ?? null;
    if ($regId) {
        $registrationIds[(string)$regId] = true;
        // capture payer for memo fallback
        $payerName = trim((string)($pay['attributes']['payer_name'] ?? ''));
        if (!isset($registrationMeta[$regId])) {
            $registrationMeta[$regId] = ['payer_name' => $payerName];
        } elseif ($payerName !== '') {
            $registrationMeta[$regId]['payer_name'] = $payerName;
        }
    }
}
foreach (array_keys($registrationIds) as $regId) {
    try {
        $regData = $pco->getRegistration((string)$regId);
        $attrs = $regData['attributes'] ?? ($regData['data']['attributes'] ?? []);
        $registrationLookup[$regId] = is_array($attrs) ? $attrs : [];
        if (isset($regData['attributes']['created_by_name']) && $regData['attributes']['created_by_name'] !== '') {
            $registrationMeta[$regId]['created_by_name'] = $regData['attributes']['created_by_name'];
        }
        if (isset($regData['relationships']['event']['data']['id'])) {
            $registrationMeta[$regId]['event_id'] = $regData['relationships']['event']['data']['id'];
        }
    } catch (Throwable $e) {
        // If we can't fetch one registration, continue; we'll fallback in descriptions.
        $registrationLookup[$regId] = [];
    }
}

$lines = [];
$feeLines = [];
$processedIds = [];
$grossTotal = 0.0;
$feeTotal   = 0.0;
$refundsCreated = 0;
$refundTotal = 0.0;
$refundCreatedIds = [];
$pmStats    = ['lines' => 0, 'with_ref' => 0, 'missing' => 0];
$missingPmIds = [];

foreach ($payments as $pay) {
    $attrs  = $pay['attributes'] ?? [];
    $registrationId   = $pay['relationships']['registration']['data']['id'] ?? null;
    if ($registrationId) {
        // Track all registration IDs, even if payment falls outside the window,
        // so we can detect refunds that happen later.
        $registrationIds[(string)$registrationId] = true;
    }
    // The newer default Registrations API removed paid_at; use created_at for windowing.
    $occurredAt = $attrs['created_at'] ?? ($attrs['paid_at'] ?? null);
    if (!$occurredAt) { continue; }
    try {
        $occurredAtDt = new DateTimeImmutable($occurredAt);
    } catch (Throwable $e) {
        continue;
    }
    if ($occurredAtDt < $sinceUtc || $occurredAtDt > $nowUtc) { continue; }

    $id     = (string)($pay['id'] ?? '');
    $gross  = ((int)($attrs['amount_cents'] ?? 0)) / 100.0;
    $fee    = ((int)($attrs['stripe_fee_cents'] ?? 0)) / 100.0;
    $net    = $gross - $fee;

    $eventId   = $pay['relationships']['event']['data']['id'] ?? null;
    $eventName = trim((string)($attrs['event_name'] ?? ''));
    if ($eventName === '') {
        $eventName = 'Event ' . ($eventId ?? '');
    }
    if ($registrationId) {
        $registrationMeta[$registrationId]['event_name'] = $eventName;
    }

    $registrationId   = $pay['relationships']['registration']['data']['id'] ?? null;
    $regAttrs         = $registrationId && isset($registrationLookup[(string)$registrationId]) ? $registrationLookup[(string)$registrationId] : [];
    $regCreatedAtRaw  = $regAttrs['created_at'] ?? null;
    $regCreatedAtText = null;
    if ($regCreatedAtRaw) {
        try {
            $regCreatedAtDt  = new DateTimeImmutable($regCreatedAtRaw);
            $regCreatedAtText = fmt_dt($regCreatedAtDt, $displayTz);
        } catch (Throwable $e) {
            $regCreatedAtText = null;
        }
    }

    $payerName = trim((string)($attrs['payer_name'] ?? ''));
    $createdBy = trim((string)($regAttrs['created_by_name'] ?? ''));
    $personName = $payerName !== '' ? $payerName : ($createdBy !== '' ? $createdBy : 'Unknown person');
    $paymentMethod = trim((string)($attrs['instrument'] ?? ''));
    $pmName = map_payment_method_name($paymentMethod);

    $descPieces = array_filter([
        'Registration: ' . $eventName,
        'Person: ' . $personName,
        $regCreatedAtText ? ('Registered: ' . $regCreatedAtText) : null,
        'Payment: ' . ($paymentMethod !== '' ? $paymentMethod : 'Unspecified'),
        'Payment created: ' . fmt_dt($occurredAtDt, $displayTz),
    ]);
    $description = implode(' | ', $descPieces);

    $line = [
        'Amount' => round($gross, 2),
        'DetailType' => 'DepositLineDetail',
        'Description' => $description,
        'DepositLineDetail' => [
            'AccountRef' => [
                'value' => (string)$incomeAccount['Id'],
                'name'  => $incomeAccount['Name'] ?? $regIncomeName,
            ],
        ],
    ];
    $pmStats['lines']++;
    if ($pmName) {
        try {
            $pmObj = $qbo->getPaymentMethodByName($pmName);
            if ($pmObj) {
                $line['DepositLineDetail']['PaymentMethodRef'] = [
                    'value' => (string)$pmObj['Id'],
                    'name'  => $pmObj['Name'] ?? $pmName,
                ];
                $pmStats['with_ref']++;
            }
        } catch (Throwable $e) {
            // ignore missing payment method and continue
        }
    } else {
        $pmStats['missing']++;
        if (count($missingPmIds) < 5) {
            $missingPmIds[] = $id;
        }
    }
    if ($classRef) {
        $line['DepositLineDetail']['ClassRef'] = $classRef;
    }
    $lines[] = $line;

    if ($fee !== 0.0) {
        $feeLine = [
            'Amount' => round($fee * -1, 2),
            'DetailType' => 'DepositLineDetail',
            'Description' => 'Fee for registration ' . $eventName,
            'DepositLineDetail' => [
                'AccountRef' => [
                    'value' => (string)$feeAccount['Id'],
                    'name'  => $feeAccount['Name'] ?? $feeAccountName,
                ],
            ],
        ];
        if ($classRef) {
            $feeLine['DepositLineDetail']['ClassRef'] = $classRef;
        }
        $lines[] = $feeLine;
    }

    $processedIds[] = $id;
    $grossTotal += $gross;
    $feeTotal   += $fee;
}

// ---------------------------------------------------------------------------
// Process refunds based on registration totals (delta since last run)
// ---------------------------------------------------------------------------

$refundAccountName = get_setting($pdo, 'stripe_refund_account_name') ?? '';
$refundAccount     = null;
try {
    if ($refundAccountName !== '') {
        $refundAccount = $qbo->getAccountByName($refundAccountName, true);
    }
} catch (Throwable $e) {
    $errors[] = 'Error looking up refund account: ' . $e->getMessage();
}
$refundAccountFallback = $incomeAccount;

foreach ($registrationLookup as $regId => $regAttrs) {
    $regId = (string)$regId;
    $currentRefundCents = parse_money_to_cents($regAttrs['total_refunds'] ?? ($regAttrs['total_refunds_cents'] ?? 0));
    $prevRefundCentsSetting = (int)(get_setting($pdo, 'reg_refunded_cents_' . $regId) ?? 0);
    $prevRefundCentsFingerprint = last_refund_total_cents($pdo, $regId);
    $prevRefundCents    = $forceRefunds ? 0 : max($prevRefundCentsSetting, $prevRefundCentsFingerprint);
    $deltaCents         = $currentRefundCents - $prevRefundCents;
    if ($deltaCents <= 0) {
        continue;
    }

    $deltaAmount = round($deltaCents / 100, 2);
    $fingerprint = 'reg_refund|' . $regId . '|' . $currentRefundCents;

    $eventName = '';
    if (isset($regAttrs['event_name'])) {
        $eventName = (string)$regAttrs['event_name'];
    } elseif (isset($registrationMeta[$regId]['event_id'])) {
        $eventName = 'Event ' . $registrationMeta[$regId]['event_id'];
    }
    $payerName = $registrationMeta[$regId]['payer_name'] ?? ($registrationMeta[$regId]['created_by_name'] ?? 'Unknown person');

    // Build an Account-based Expense (Purchase) with the refund account line.
    $line = [
        'Amount' => $deltaAmount,
        'DetailType' => 'AccountBasedExpenseLineDetail',
        'Description' => 'Registration refund: ' . $eventName,
        'AccountBasedExpenseLineDetail' => [
            'AccountRef' => [
                'value' => (string)($refundAccount['Id'] ?? $refundAccountFallback['Id']),
                'name'  => ($refundAccount['Name'] ?? $refundAccountName) ?: ($refundAccountFallback['Name'] ?? $regIncomeName),
            ],
        ],
    ];
    if ($classRef) {
        $line['AccountBasedExpenseLineDetail']['ClassRef'] = $classRef;
    }

    $purchase = [
        'TxnDate' => $nowUtc->format('Y-m-d'),
        'PaymentType' => 'Cash',
        // Bank/cash account the refund leaves from (same as deposit account for simplicity)
        'AccountRef' => [
            'value' => (string)$depositAccount['Id'],
            'name'  => $depositAccount['Name'] ?? $regDepositName,
        ],
        'PrivateNote' => 'Registration refund ' . $regId . ' (' . $eventName . ') payer: ' . $payerName,
        'TotalAmt' => $deltaAmount,
        'Line' => [$line],
    ];
    if ($deptRef) {
        $purchase['DepartmentRef'] = $deptRef;
    }

    try {
        $resp = $qbo->createPurchase($purchase);
        mark_synced_deposit($pdo, 'registrations_refund', $fingerprint);
        set_setting($pdo, 'reg_refunded_cents_' . $regId, (string)$currentRefundCents);
        $refundsCreated++;
        $refundTotal += $deltaAmount;
        $refundCreatedIds[] = $regId;
    } catch (Throwable $e) {
        $errors[] = 'Error creating refund expense for registration ' . $regId . ': ' . $e->getMessage();
    }
}

// ---------------------------------------------------------------------------
// If nothing to do, exit early
// ---------------------------------------------------------------------------

if (empty($lines) && $refundsCreated === 0) {
    set_setting($pdo, 'last_registrations_paid_at', $nowUtc->format(DateTimeInterface::ATOM));
    echo '<h1>Registrations sync</h1>';
    echo '<p>No payments found in this window (' .
        htmlspecialchars(fmt_dt($sinceUtc, $displayTz), ENT_QUOTES, 'UTF-8') . ' to ' .
        htmlspecialchars(fmt_dt($nowUtc, $displayTz), ENT_QUOTES, 'UTF-8') . ').</p>';
    echo '<p>You can widen the window by adding <code>?reset_window=1&backfill_days=30</code> to this URL.</p>';
    echo '<p><a href="index.php">&larr; Back to dashboard</a></p>';
    exit;
}

// Build deposit if we have payment lines
$depositResult = null;
if (!empty($lines)) {
    $deposit = [
        'TxnDate' => $nowUtc->format('Y-m-d'),
        'PrivateNote' => 'Registrations created_at ' . $sinceUtc->format('Y-m-d H:i:s') . ' to ' . $nowUtc->format('Y-m-d H:i:s'),
        'DepositToAccountRef' => [
            'value' => (string)$depositAccount['Id'],
            'name'  => $depositAccount['Name'] ?? $regDepositName,
        ],
        'Line' => $lines,
    ];

    if ($deptRef) {
        $deposit['DepartmentRef'] = $deptRef;
    }

    // Idempotency
    $fingerprint = hash('sha256', json_encode([
        'type' => 'registrations',
        'ids' => $processedIds,
        'account' => $depositAccount['Id'] ?? '',
    ]));

    $depositAlreadySynced = has_synced_deposit($pdo, 'registrations', $fingerprint);

    try {
        if (!$depositAlreadySynced) {
            $resp = $qbo->createDeposit($deposit);
            $depositResult = $resp['Deposit'] ?? $resp;
            mark_synced_deposit($pdo, 'registrations', $fingerprint);
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}
$status = empty($errors) ? 'success' : 'error';
if ($status === 'success') {
    set_setting($pdo, 'last_registrations_paid_at', $nowUtc->format(DateTimeInterface::ATOM));
}
$summaryData = [
    'ts'        => $nowUtc->format(DateTimeInterface::ATOM),
    'status'    => $status,
    'payments'  => count($processedIds),
    'refunds'   => $refundsCreated,
    'refund_total' => round($refundTotal, 2),
    'window'    => [
        'since' => $sinceUtc->format(DateTimeInterface::ATOM),
        'until' => $nowUtc->format(DateTimeInterface::ATOM),
    ],
    'payment_method_lines_total' => $pmStats['lines'],
    'payment_method_lines_with_ref' => $pmStats['with_ref'],
    'payment_method_missing'        => $pmStats['missing'],
    'missing_payment_ids'           => $missingPmIds,
    'errors'    => $errors,
];
set_setting($pdo, 'last_registrations_sync_summary', json_encode($summaryData));

if ($notificationEmail && in_array($status, ['error', 'partial'], true)) {
    $from   = $config['mail']['from'] ?? null;
    $mailer = new Mailer($from);
    $subject = '[PCO->QBO] Registrations sync ' . strtoupper($status);
    $bodyLines = [
        'Registrations sync run on ' . $nowUtc->format('Y-m-d H:i:s T'),
        'Status: ' . $status,
        'Payments: ' . count($processedIds),
        'Window: ' . $sinceUtc->format('Y-m-d H:i:s') . ' to ' . $nowUtc->format('Y-m-d H:i:s'),
    ];
    if (!empty($missingPmIds)) {
        $bodyLines[] = '';
        $bodyLines[] = 'Missing payment method on payment ids: ' . implode(', ', $missingPmIds);
    }
    if (!empty($errors)) {
        $bodyLines[] = '';
        $bodyLines[] = 'Errors:';
        $bodyLines = array_merge($bodyLines, $errors);
    }
    $mailer->send($notificationEmail, $subject, implode("\n", $bodyLines));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Registrations Sync Result</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Manrope:wght@500;700&display=swap');
        :root {
            --bg: #0b1224;
            --panel: rgba(15, 25, 46, 0.78);
            --card: rgba(22, 32, 55, 0.9);
            --border: rgba(255, 255, 255, 0.08);
            --text: #e9eef7;
            --muted: #9daccc;
            --accent: #2ea8ff;
            --accent-strong: #0d7adf;
            --success: #39d98a;
            --warn: #f2c94c;
            --error: #ff7a7a;
        }
        body { font-family: 'Manrope','Segoe UI',sans-serif; margin:0; background: radial-gradient(circle at 15% 20%, rgba(46,168,255,0.12), transparent 25%), radial-gradient(circle at 85% 10%, rgba(57,217,138,0.15), transparent 22%), radial-gradient(circle at 70% 70%, rgba(242,201,76,0.08), transparent 30%), var(--bg); color: var(--text); min-height: 100vh; }
        * { box-sizing: border-box; }
        .page { max-width: 1100px; margin:0 auto; padding:2.4rem 1.25rem 3rem; }
        .card { background: var(--card); border:1px solid var(--border); border-radius:14px; padding:1.2rem 1.25rem; box-shadow:0 8px 30px rgba(0,0,0,0.18); margin-bottom:1rem; }
        h1 { margin:0 0 0.5rem; }
        .muted { color: var(--muted); }
        .flash { margin-bottom:1rem; padding:0.85rem 1rem; border-radius:10px; border:1px solid var(--border); }
        .flash.success { background: rgba(57,217,138,0.12); border-color: rgba(57,217,138,0.35); }
        .flash.error { background: rgba(255,122,122,0.12); border-color: rgba(255,122,122,0.35); }
        .footer { margin-top:1.4rem; text-align:center; color: var(--muted); font-size:0.9rem; }
        .footer a { color: var(--accent); text-decoration:none; }
        .footer a:hover { color:#dff1ff; }
        table { width:100%; border-collapse: collapse; margin-top:0.6rem; }
        th, td { border-bottom:1px solid rgba(255,255,255,0.08); padding:0.55rem 0.65rem; text-align:left; }
        th { color: var(--muted); }
    </style>
</head>
<body>
<div class="page">
    <h1>Registrations Sync Result</h1>

    <p class="muted">
        <a style="color:#fff;" href="?reset_window=1&backfill_days=7">Reset window to 7d</a> |
        <a style="color:#fff;" href="?backfill_days=7&force_refunds=1">Force refunds (ignore prior totals)</a>
    </p>

<?php if (!empty($errors)): ?>
    <div class="flash error">
        <strong>Errors occurred:</strong>
        <ul>
            <?php foreach ($errors as $err): ?>
                <li><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
        <?php if ($depositResult): ?>
            <p class="muted">A deposit may have been created before the errors. Review QBO if you retry.</p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="flash success">
        <div>
            <div><strong><?= $depositResult ? 'Deposit processed.' : 'No deposits were created.' ?></strong></div>
            <div class="muted">
                Refunds created: <?= (int)$refundsCreated ?> (<?= '$' . number_format($refundTotal, 2) ?>)
                <?php if (!empty($refundCreatedIds)): ?>
                    — Reg IDs: <?= htmlspecialchars(implode(', ', $refundCreatedIds), ENT_QUOTES, 'UTF-8') ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($processedIds) || $refundsCreated > 0): ?>
    <div class="card">
        <p class="muted">
            Window used (created_at):
            <strong><?= htmlspecialchars(fmt_dt($sinceUtc, $displayTz), ENT_QUOTES, 'UTF-8') ?></strong>
            to
            <strong><?= htmlspecialchars(fmt_dt($nowUtc, $displayTz), ENT_QUOTES, 'UTF-8') ?></strong>
            (<?= htmlspecialchars($displayTz->getName(), ENT_QUOTES, 'UTF-8') ?>)
        </p>
        <p class="muted">Quick window reset:
            <a style="color: #fff;" href="?reset_window=1&backfill_days=1">24h</a> |
            <a style="color: #fff;" href="?reset_window=1&backfill_days=7">7d</a> |
            <a style="color: #fff;" href="?reset_window=1&backfill_days=30">30d</a>
        </p>
        <table>
            <tr><th>Payments processed</th><td><?= count($processedIds) ?></td></tr>
            <tr><th>Gross</th><td>$<?= number_format($grossTotal, 2) ?></td></tr>
            <tr><th>Fees</th><td>$<?= number_format($feeTotal, 2) ?></td></tr>
            <tr><th>Net</th><td>$<?= number_format($grossTotal - $feeTotal, 2) ?></td></tr>
            <tr><th>Refunds created</th><td><?= (int)$refundsCreated ?> ($<?= number_format($refundTotal, 2) ?>)</td></tr>
                <?php if ($depositResult): ?>
                    <tr><th>QBO Deposit Id</th><td><?= htmlspecialchars((string)($depositResult['Id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
                <?php endif; ?>
            </table>
        </div>
<?php endif; ?>

    <p><a href="index.php">&larr; Back to dashboard</a></p>
    <div class="footer">
        &copy; <?= date('Y') ?> Rev. Tommy Sheppard • <a href="help.php">Help</a>
    </div>
</div>
</body>
</html>
