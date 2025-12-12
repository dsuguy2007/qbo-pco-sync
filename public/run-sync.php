<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/PcoClient.php';
require_once __DIR__ . '/../src/SyncService.php';
require_once __DIR__ . '/../src/QboClient.php';
require_once __DIR__ . '/../src/SyncLogger.php';
require_once __DIR__ . '/../src/Auth.php';

Auth::requireLogin();

/**
 * Get a setting from sync_settings.
 */
function get_setting(PDO $pdo, string $key): ?string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM sync_settings WHERE setting_key = :key ORDER BY id DESC LIMIT 1');
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && isset($row['setting_value'])) {
        return (string)$row['setting_value'];
    }
    return null;
}

/**
 * Set a setting in sync_settings (one row per key).
 */
function set_setting(PDO $pdo, string $key, string $value): void
{
    // Ensure only one row per setting_key
    $stmt = $pdo->prepare('DELETE FROM sync_settings WHERE setting_key = :key');
    $stmt->execute([':key' => $key]);

    $stmt = $pdo->prepare('INSERT INTO sync_settings (setting_key, setting_value) VALUES (:key, :value)');
    $stmt->execute([
        ':key'   => $key,
        ':value' => $value,
    ]);
}

/**
 * Build a deterministic fingerprint for a Stripe deposit so we can avoid
 * creating duplicates if the sync is re-run.
 *
 * We intentionally only hash the location key, bank account id, and line
 * details (amounts/classes), not volatile fields like TxnDate.
 */
function build_stripe_deposit_fingerprint(array $deposit, string $locKey): string
{
    $payloadForHash = [
        'locKey' => $locKey,
        'bank'   => $deposit['DepositToAccountRef']['value'] ?? null,
        'lines'  => $deposit['Line'] ?? [],
    ];

    return hash(
        'sha256',
        json_encode($payloadForHash, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

/**
 * Check whether we've already synced a Stripe deposit with this fingerprint.
 */
function has_synced_stripe_deposit(PDO $pdo, string $fingerprint): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM synced_deposits WHERE type = :type AND fingerprint = :fp LIMIT 1');
    $stmt->execute([
        ':type' => 'stripe',
        ':fp'   => $fingerprint,
    ]);

    return (bool)$stmt->fetchColumn();
}

/**
 * Record that we've successfully synced a Stripe deposit with this fingerprint.
 */
function mark_synced_stripe_deposit(PDO $pdo, string $fingerprint): void
{
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO synced_deposits (type, fingerprint, created_at)
         VALUES (:type, :fp, :created_at)'
    );

    $stmt->execute([
        ':type'       => 'stripe',
        ':fp'         => $fingerprint,
        ':created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
    ]);
}

// --- Bootstrap DB / clients --------------------------------------------------

try {
    $db  = Db::getInstance($config['db']);
    $pdo = $db->getConnection();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Sync error</h1>';
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

$service = new SyncService($pdo, $pco);

// --- Determine sync window ---------------------------------------------------

$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));

// last_completed_at is stored as ISO8601 in UTC.
$lastSyncStr = get_setting($pdo, 'last_completed_at');

if ($lastSyncStr === null) {
    // First run: we *intentionally* do NOT backfill history.
    // We set last_completed_at to "now" and exit.
    set_setting($pdo, 'last_completed_at', $nowUtc->format(DateTimeInterface::ATOM));
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PCO &rarr; QBO Sync</title>
    <style>
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 2rem; }
        .muted { font-size: 0.9rem; color: #666; }
    </style>
</head>
<body>
<h1>PCO &rarr; QBO Sync</h1>
<p>This is the first time the Stripe sync has been run.</p>
<p class="muted">
    We have recorded the current time as the starting point for future syncs:
    <strong><?= htmlspecialchars($nowUtc->format('Y-m-d H:i:s T'), ENT_QUOTES, 'UTF-8') ?></strong>.
    No historical donations were imported.
</p>
<p><a href="index.php">&larr; Back to dashboard</a></p>
</body>
</html>
<?php
    exit;
}

try {
    $sinceUtc = new DateTimeImmutable($lastSyncStr);
} catch (Throwable $e) {
    // If the stored value is bad, fall back to "now" and don't sync anything.
    set_setting($pdo, 'last_completed_at', $nowUtc->format(DateTimeInterface::ATOM));
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PCO &rarr; QBO Sync</title>
    <style>
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 2rem; }
        .error { padding: 0.6rem 0.8rem; background: #ffecec; border: 1px solid #ffaeae; margin-bottom: 1rem; }
        .muted { font-size: 0.9rem; color: #666; }
    </style>
</head>
<body>
<h1>PCO &rarr; QBO Sync</h1>
<div class="error">
    <strong>Invalid last_completed_at value was stored.</strong>
    We reset it to now without importing any donations.
</div>
<p class="muted">
    New value:
    <strong><?= htmlspecialchars($nowUtc->format('Y-m-d H:i:s T'), ENT_QUOTES, 'UTF-8') ?></strong>
</p>
<p><a href="index.php">&larr; Back to dashboard</a></p>
</body>
</html>
<?php
    exit;
}

// --- Build preview of what we *would* deposit --------------------------------

try {
    $preview = $service->buildDepositPreview($sinceUtc, $nowUtc);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Sync error</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

// If there are no funds to deposit, simply move the window forward.
if (empty($preview['funds'])) {
    set_setting($pdo, 'last_completed_at', $nowUtc->format(DateTimeInterface::ATOM));
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PCO &rarr; QBO Sync</title>
    <style>
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 2rem; }
        .muted { font-size: 0.9rem; color: #666; }
    </style>
</head>
<body>
<h1>PCO &rarr; QBO Sync</h1>
<p>No eligible Stripe donations were found to sync in this window.</p>
<p class="muted">
    Last completed sync window is now set to:
    <strong><?= htmlspecialchars($nowUtc->format('Y-m-d H:i:s T'), ENT_QUOTES, 'UTF-8') ?></strong> (based on completed_at).
</p>
<p><a href="index.php">&larr; Back to dashboard</a></p>
</body>
</html>
<?php
    exit;
}

// --- Look up QBO accounts we need --------------------------------------------

$errors          = [];
$createdDeposits = [];

$depositBankName      = $config['qbo']['stripe_deposit_bank'] ?? 'TRINITY 2000 CHECKING';
$weeklyIncomeName     = $config['qbo']['stripe_income_account'] ?? 'OPERATING INCOME:WEEKLY OFFERINGS:PLEDGES';
$stripeFeeAccountName = $config['qbo']['stripe_fee_account'] ?? 'OPERATING EXPENSES:MINISTRY EXPENSES:PROCESSING FEES';

try {
    $bankAccount = $qbo->getAccountByName($depositBankName, true);
    if (!$bankAccount) {
        $errors[] = "Could not find bank account in QBO: {$depositBankName}";
    }

    $incomeAccount = $qbo->getAccountByName($weeklyIncomeName, true);
    if (!$incomeAccount) {
        $errors[] = "Could not find income account in QBO: {$weeklyIncomeName}";
    }

    $feeAccount = $qbo->getAccountByName($stripeFeeAccountName, true);
    if (!$feeAccount) {
        $errors[] = "Could not find Stripe fee account in QBO: {$stripeFeeAccountName}";
    }
} catch (Throwable $e) {
    $errors[] = 'Error looking up QBO accounts: ' . $e->getMessage();
}

// --- Group funds by Location (one Deposit per Location) ----------------------

$locationGroups = [];

/** @var array $row */
foreach ($preview['funds'] as $row) {
    $locName = trim((string)($row['qbo_location_name'] ?? ''));
    $locKey  = $locName !== '' ? $locName : '__NO_LOCATION__';

    if (!isset($locationGroups[$locKey])) {
        $locationGroups[$locKey] = [
            'location_name' => $locName,   // '' means "no location"
            'funds'         => [],
            'total_gross'   => 0.0,
            'total_fee'     => 0.0,
            'total_net'     => 0.0,
        ];
    }

    $locationGroups[$locKey]['funds'][]      = $row;
    $locationGroups[$locKey]['total_gross'] += (float)$row['gross'];
    $locationGroups[$locKey]['total_fee']   += (float)$row['fee'];
    $locationGroups[$locKey]['total_net']   += (float)$row['net'];
}

// --- Build and send one Deposit per Location --------------------------------

if (empty($errors)) {
    foreach ($locationGroups as $locKey => $group) {
        $locName = $group['location_name'];

        // Look up QBO Department (Location) if one is mapped
        $deptRef = null;
        if ($locName !== '') {
            try {
                $deptObj = $qbo->getDepartmentByName($locName);
                if (!$deptObj) {
                    $errors[] = "Could not find QBO Location (Department) for '{$locName}'.";
                    continue;
                }
                $deptRef = [
                    'value' => (string)$deptObj['Id'],
                    'name'  => (string)($deptObj['Name'] ?? $locName),
                ];
            } catch (Throwable $e) {
                $errors[] = 'Error looking up QBO Location (Department) for ' . $locName . ': ' . $e->getMessage();
                continue;
            }
        }

        $lines = [];

        foreach ($group['funds'] as $fundRow) {
            $fundName  = $fundRow['pco_fund_name'];
            $className = $fundRow['qbo_class_name'];

            // Look up QBO Class per fund, if mapped
            $classId = null;
            if ($className) {
                try {
                    $classObj = $qbo->getClassByName($className);
                    if (!$classObj) {
                        $errors[] = "Could not find QBO Class for fund '{$fundName}' (expected: '{$className}').";
                        continue 2; // skip this entire location
                    }
                    $classId = (string)$classObj['Id'];
                } catch (Throwable $e) {
                    $errors[] = 'Error looking up QBO Class for fund ' . $fundName . ': ' . $e->getMessage();
                    continue 2; // skip this entire location
                }
            }

            $gross = (float)$fundRow['gross'];
            $fee   = (float)$fundRow['fee'];
            $net   = (float)$fundRow['net'];

            // Gross line (income)
            $line = [
                'Amount'     => round($gross, 2),
                'DetailType' => 'DepositLineDetail',
                'DepositLineDetail' => [
                    'AccountRef' => [
                        'value' => (string)$incomeAccount['Id'],
                        'name'  => $incomeAccount['Name'] ?? $weeklyIncomeName,
                    ],
                ],
            ];
            if ($classId !== null) {
                $line['DepositLineDetail']['ClassRef'] = [
                    'value' => $classId,
                    'name'  => $className,
                ];
            }
            $lines[] = $line;

            // Fee line (negative)
            if ($fee !== 0.0) {
                $feeLine = [
                    'Amount'     => round($fee, 2), // fee is negative in preview
                    'DetailType' => 'DepositLineDetail',
                    'DepositLineDetail' => [
                        'AccountRef' => [
                            'value' => (string)$feeAccount['Id'],
                            'name'  => $feeAccount['Name'] ?? $stripeFeeAccountName,
                        ],
                    ],
                ];
                if ($classId !== null) {
                    $feeLine['DepositLineDetail']['ClassRef'] = [
                        'value' => $classId,
                        'name'  => $className,
                    ];
                }
                $lines[] = $feeLine;
            }
        }

        if (empty($lines)) {
            continue;
        }

        // Build Deposit payload according to QBO spec
        $deposit = [
            'TxnDate' => $nowUtc->format('Y-m-d'),
            'PrivateNote' => 'PCO Stripe sync: completed_at ' .
                $preview['since']->format('Y-m-d H:i:s') . ' to ' .
                $preview['until']->format('Y-m-d H:i:s') .
                ($locName ? (' | Location: ' . $locName) : ''),
            'DepositToAccountRef' => [
                'value' => (string)$bankAccount['Id'],
                'name'  => $bankAccount['Name'] ?? $depositBankName,
            ],
            'Line' => $lines,
        ];

        // One DepartmentRef (Location) per Deposit, per QBO rules
        if ($deptRef !== null) {
            $deposit['DepartmentRef'] = $deptRef;
        }

        // --- Idempotency: skip if we've already created an identical Stripe deposit
        $fingerprint = build_stripe_deposit_fingerprint($deposit, (string)$locKey);
        if (has_synced_stripe_deposit($pdo, $fingerprint)) {
            // Record that we saw this deposit but skipped it to avoid duplicates
            $createdDeposits[] = [
                'location_name' => $locName,
                'total_gross'   => $group['total_gross'],
                'total_fee'     => $group['total_fee'],
                'total_net'     => $group['total_net'],
                'deposit'       => null,
                'skipped'       => true,
            ];
            continue;
        }

        try {
            $resp = $qbo->createDeposit($deposit);
            $dep  = $resp['Deposit'] ?? null;

            // Mark this deposit as synced so re-runs don't double-book it
            mark_synced_stripe_deposit($pdo, $fingerprint);

            $createdDeposits[] = [
                'location_name' => $locName,
                'total_gross'   => $group['total_gross'],
                'total_fee'     => $group['total_fee'],
                'total_net'     => $group['total_net'],
                'deposit'       => $dep,
                'skipped'       => false,
            ];
        } catch (Throwable $e) {
            $errors[] = 'Error creating QBO Deposit for Location ' . ($locName ?: '(no location)') . ': ' . $e->getMessage();
        }
    }
}

// Only move the sync window forward if everything succeeded
if (empty($errors) && !empty($createdDeposits)) {
    set_setting($pdo, 'last_completed_at', $preview['until']->format(DateTimeInterface::ATOM));
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PCO &rarr; QBO Stripe Sync Result</title>
    <style>
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 2rem; }
        .ok { padding: 0.6rem 0.8rem; background: #e6ffed; border: 1px solid #b7eb8f; margin-bottom: 1rem; }
        .error { padding: 0.6rem 0.8rem; background: #ffecec; border: 1px solid #ffaeae; margin-bottom: 1rem; }
        table { border-collapse: collapse; width: 100%; max-width: 900px; }
        th, td { border: 1px solid #ccc; padding: 0.4rem 0.6rem; font-size: 0.9rem; text-align: left; }
        th { background: #f5f5f5; }
        .muted { font-size: 0.85rem; color: #666; }
    </style>
</head>
<body>
<h1>PCO &rarr; QBO Stripe Sync Result</h1>

<?php if (!empty($errors)): ?>
    <div class="error">
        <strong>Sync completed with errors.</strong>
        <ul>
            <?php foreach ($errors as $err): ?>
                <li><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
        <?php if (!empty($createdDeposits)): ?>
            <p class="muted">
                Some deposits may have been created in QuickBooks before the errors occurred.
                Review QBO and adjust the <code>last_completed_at</code> setting if you need to rerun this window.
            </p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <?php if (!empty($createdDeposits)): ?>
        <div class="ok">
            <strong><?= count($createdDeposits) ?> deposit(s) created or already present in QuickBooks.</strong>
        </div>
    <?php else: ?>
        <div class="ok">
            <strong>No deposits were created.</strong>
        </div>
    <?php endif; ?>
<?php endif; ?>

<h2>Window used</h2>
<p class="muted">
    Completed_at from
    <strong><?= htmlspecialchars($preview['since']->format('Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8') ?></strong>
    to
    <strong><?= htmlspecialchars($preview['until']->format('Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8') ?></strong>
</p>

<?php if (!empty($createdDeposits)): ?>
    <h2>Deposits by Location</h2>
    <table>
        <thead>
        <tr>
            <th>Location</th>
            <th>QBO Deposit Id</th>
            <th>TxnDate</th>
            <th>QBO Total</th>
            <th>Gross</th>
            <th>Fees</th>
            <th>Net</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($createdDeposits as $cd): ?>
            <?php
                $dep     = $cd['deposit'] ?? [];
                $skipped = $cd['skipped'] ?? false;
            ?>
            <tr>
                <td><?= htmlspecialchars($cd['location_name'] ?: '(no location)', ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                    <?php if ($skipped): ?>
                        <span class="muted">skipped (already synced)</span>
                    <?php else: ?>
                        <?= htmlspecialchars((string)($dep['Id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                    <?php endif; ?>
                </td>
                <td><?= $skipped ? '' : htmlspecialchars((string)($dep['TxnDate'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= $skipped ? '' : ('$' . htmlspecialchars((string)($dep['TotalAmt'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></td>
                <td>$<?= number_format($cd['total_gross'], 2) ?></td>
                <td>$<?= number_format($cd['total_fee'], 2) ?></td>
                <td>$<?= number_format($cd['total_net'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<p style="margin-top:1.5rem;"><a href="index.php">&larr; Back to dashboard</a></p>

</body>
</html>
