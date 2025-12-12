<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/QboClient.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/SyncLogger.php';
require_once __DIR__ . '/../src/Mailer.php'; 

Auth::requireLogin();
// DEBUG: prove we are in the correct run-sync.php
$debugRoot = dirname(__DIR__); // /qbo-pco-sync
$debugFile = $debugRoot . '/email-debug.log';

file_put_contents(
    $debugFile,
    date('c') . " run-sync.php TOP reached\n",
    FILE_APPEND
);

/**
 * Read a setting from the sync_settings table.
 */
function get_setting(PDO $pdo, string $key): ?string
{
    $stmt = $pdo->prepare(
        'SELECT setting_value 
           FROM sync_settings 
          WHERE setting_key = :key 
          ORDER BY id DESC 
          LIMIT 1'
    );
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && isset($row['setting_value'])) {
        return (string)$row['setting_value'];
    }
    return null;
}

/**
 * Write a setting to the sync_settings table (one row per key).
 */
function set_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare('DELETE FROM sync_settings WHERE setting_key = :key');
    $stmt->execute([':key' => $key]);

    $stmt = $pdo->prepare(
        'INSERT INTO sync_settings (setting_key, setting_value) 
         VALUES (:key, :value)'
    );
    $stmt->execute([
        ':key'   => $key,
        ':value' => $value,
    ]);
}

function send_sync_email_if_needed(
    PDO $pdo,
    array $config,
    string $syncLabel,
    string $status,
    string $summary,
    ?string $details
): void {
    // Debug log for the helper itself
    $root    = dirname(__DIR__); // /qbo-pco-sync
    $logFile = $root . '/email-debug.log';

    $notificationEmail = get_setting($pdo, 'notification_email');

    $logLines = [];
    $logLines[] = sprintf(
        "[%s] send_sync_email_if_needed called",
        date('c')
    );
    $logLines[] = '  syncLabel:          ' . $syncLabel;
    $logLines[] = '  status:             ' . $status;
    $logLines[] = '  notification_email: ' . ($notificationEmail ?? 'NULL');
    $logLines[] = '';

    @file_put_contents($logFile, implode("\n", $logLines) . "\n", FILE_APPEND);

    // If no email configured or status is success, skip sending
    if (!$notificationEmail || !in_array($status, ['error', 'partial'], true)) {
        $skipLines = [];
        $skipLines[] = sprintf(
            "[%s] Skipping email send (either no email set or status not error/partial).",
            date('c')
        );
        $skipLines[] = str_repeat('-', 60);
        @file_put_contents($logFile, implode("\n", $skipLines) . "\n", FILE_APPEND);
        return;
    }

    $from   = $config['mail']['from'] ?? null;
    $mailer = new Mailer($from);

    $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    $subject = "[PCO→QBO] {$syncLabel} sync " . strtoupper($status);
    $bodyLines = [
        "{$syncLabel} sync run on " . $nowUtc->format('Y-m-d H:i:s T'),
        '',
        'Status: ' . $status,
        $summary,
    ];

    if (!empty($details)) {
        $bodyLines[] = '';
        $bodyLines[] = 'Details:';
        $bodyLines[] = $details;
    }

    $mailer->send($notificationEmail, $subject, implode("\n", $bodyLines));
}



/**
 * Low-level helper to call the PCO API.
 */
function pco_request(array $pcoConfig, string $path, array $query = []): array
{
    $baseUrl = rtrim($pcoConfig['base_url'] ?? 'https://api.planningcenteronline.com', '/');
    $url     = $baseUrl . $path;

    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_USERPWD        => $pcoConfig['app_id'] . ':' . $pcoConfig['secret'],
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);

    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Error calling PCO: ' . $err);
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('PCO HTTP error ' . $status . ': ' . $body);
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException(
            'PCO JSON decode error: ' . json_last_error_msg() . ' | Raw body: ' . $body
        );
    }

    return $decoded;
}

/**
 * Fetch Stripe-like donations (card/ach, succeeded, completed_at in window).
 * Returns array of donations with designations attached.
 */
function get_stripe_donations(
    array $pcoConfig,
    DateTimeImmutable $windowStart,
    DateTimeImmutable $windowEnd
): array {
    $resp = pco_request(
        $pcoConfig,
        '/giving/v2/donations',
        [
            'per_page' => 100,
            'order'    => 'completed_at',
        ]
    );

    $donations = [];

    foreach ($resp['data'] ?? [] as $don) {
        $id    = (string)($don['id'] ?? '');
        $attrs = $don['attributes'] ?? [];

        if ($id === '') {
            continue;
        }

        $paymentMethod = (string)($attrs['payment_method'] ?? '');
        $paymentStatus = (string)($attrs['payment_status'] ?? '');
        $completedStr  = $attrs['completed_at'] ?? null;

        if (!in_array($paymentMethod, ['card', 'ach'], true)) {
            continue;
        }
        if ($paymentStatus !== 'succeeded') {
            continue;
        }
        if (!$completedStr) {
            continue;
        }

        try {
            $completedAt = new DateTimeImmutable($completedStr);
        } catch (Throwable $e) {
            continue;
        }

        // Only within our window (strictly > start, <= end)
        if (!($completedAt > $windowStart && $completedAt <= $windowEnd)) {
            continue;
        }

        $feeCents    = (int)($attrs['fee_cents'] ?? 0);       // often negative
        $amountCents = (int)($attrs['amount_cents'] ?? 0);

        // Fetch designations for this donation
        $desResp = pco_request(
            $pcoConfig,
            '/giving/v2/donations/' . rawurlencode($id) . '/designations',
            [
                'per_page' => 100,
            ]
        );

        $designations = [];
        $totalDesCents = 0;

        foreach ($desResp['data'] ?? [] as $des) {
            $dAttrs = $des['attributes'] ?? [];
            $rels   = $des['relationships'] ?? [];
            $fund   = $rels['fund']['data'] ?? null;

            if (!$fund) {
                continue;
            }

            $fundId      = (string)($fund['id'] ?? '');
            $desCents    = (int)($dAttrs['amount_cents'] ?? 0);

            if ($fundId === '' || $desCents === 0) {
                continue;
            }

            $totalDesCents += $desCents;

            $designations[] = [
                'fund_id'      => $fundId,
                'amount_cents' => $desCents,
            ];
        }

        if (empty($designations) || $totalDesCents === 0) {
            continue;
        }

        // Allocate fee across designations (proportionally)
        foreach ($designations as $idx => $d) {
            $share = $d['amount_cents'] / $totalDesCents;
            $designations[$idx]['fee_cents'] = (int)round($feeCents * $share);
        }

        $donations[] = [
            'id'            => $id,
            'completed_at'  => $completedAt,
            'amount_cents'  => $amountCents,
            'fee_cents'     => $feeCents,
            'payment_method'=> $paymentMethod,
            'designations'  => $designations,
        ];
    }

    return $donations;
}

// ---------------------------------------------------------------------------
// Bootstrap DB / clients / logger
// ---------------------------------------------------------------------------

try {
    $db  = Db::getInstance($config['db']);
    $pdo = $db->getConnection();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Stripe sync error</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

$logger = new SyncLogger($pdo);
$logId  = $logger->start('stripe');

$pcoConfig = $config['pco'] ?? [];
if (empty($pcoConfig['app_id']) || empty($pcoConfig['secret'])) {
    $status  = 'error';
    $summary = 'PCO credentials not configured.';
    $details = 'Missing pco.app_id or pco.secret in config.php.';

    $logger->finish($logId, $status, $summary, $details);
    send_sync_email_if_needed($pdo, $config, 'Stripe', $status, $summary, $details);

    http_response_code(500);
    echo '<h1>PCO client error</h1>';
    echo '<p>PCO credentials are not configured. Please set pco.app_id and pco.secret in config.php.</p>';
    exit;
}


$errors          = [];
$createdDeposits = [];
$totalDonations  = 0;

try {
    $qbo = new QboClient($pdo, $config);
} catch (Throwable $e) {
    $errors[] = 'Error creating QBO client: ' . $e->getMessage();
    $status   = 'error';
    $summary  = 'Failed to create QBO client.';
    $details  = $errors[0];

    $logger->finish($logId, $status, $summary, $details);
    send_sync_email_if_needed($pdo, $config, 'Stripe', $status, $summary, $details);

    http_response_code(500);
    echo '<h1>QBO client error</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}


// ---------------------------------------------------------------------------
// Determine sync window based on Donation.completed_at
// ---------------------------------------------------------------------------

$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$lastCompletedStr = get_setting($pdo, 'last_completed_at');

if ($lastCompletedStr) {
    try {
        $sinceUtc = new DateTimeImmutable($lastCompletedStr);
    } catch (Throwable $e) {
        $sinceUtc = $nowUtc;
    }
} else {
    // First-ever run: initialize window and exit without backfilling
    set_setting($pdo, 'last_completed_at', $nowUtc->format(DateTimeInterface::ATOM));

    $summary = 'Initial Stripe sync run: window initialized, no donations processed.';
    $logger->finish($logId, 'success', $summary, null);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>PCO → QBO Stripe Sync</title>
        <style>
            body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 2rem; }
            .muted { font-size: 0.9rem; color: #666; }
        </style>
    </head>
    <body>
    <h1>PCO → QBO Stripe Sync</h1>
    <p>This is the first time Stripe sync has been run.</p>
    <p class="muted">
        We have initialized the sync window at
        <strong><?= htmlspecialchars($nowUtc->format('Y-m-d H:i:s T'), ENT_QUOTES, 'UTF-8') ?></strong>.
        No historical Stripe donations were synced. Next run will pick up donations completed after this time.
    </p>
    <p><a href="index.php">&larr; Back to dashboard</a></p>
    </body>
    </html>
    <?php
    exit;
}

// ---------------------------------------------------------------------------
// Look up QBO accounts and fund mappings
// ---------------------------------------------------------------------------

$depositBankName = get_setting($pdo, 'deposit_bank_account_name')
    ?? 'TRINITY 2000 CHECKING';

$incomeAccountName = get_setting($pdo, 'income_account_name')
    ?? 'OPERATING INCOME:WEEKLY OFFERINGS:PLEDGES';

$feeAccountName = get_setting($pdo, 'fee_account_name')
    ?? 'OPERATING EXPENSES:MINISTRY EXPENSES:PROCESSING FEES';

try {
    // Bank account – match by simple Name
    $bankAccount = $qbo->getAccountByName($depositBankName, false);
    if (!$bankAccount) {
        $errors[] = "Could not find deposit bank account in QBO: {$depositBankName}";
    }

    // Income account – match by FullyQualifiedName
    $incomeAccount = $qbo->getAccountByName($incomeAccountName, true);
    if (!$incomeAccount) {
        $errors[] = "Could not find income account in QBO: {$incomeAccountName}";
    }

    // Fee account – match by FullyQualifiedName
    $feeAccount = $qbo->getAccountByName($feeAccountName, true);
    if (!$feeAccount) {
        $errors[] = "Could not find fee account in QBO: {$feeAccountName}";
    }
} catch (Throwable $e) {
    $errors[] = 'Error looking up QBO accounts: ' . $e->getMessage();
}

// Load fund mappings (fund → location / class)
$fundMappings = [];
try {
    $stmt = $pdo->query('SELECT * FROM fund_mappings');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $fundId = (string)($row['pco_fund_id'] ?? '');
        if ($fundId === '') {
            continue;
        }
        $fundMappings[$fundId] = $row;
    }
} catch (Throwable $e) {
    $errors[] = 'Error loading fund mappings: ' . $e->getMessage();
}

// If we already have fatal-ish config errors, bail before PCO calls
if (!empty($errors) && (empty($bankAccount) || empty($incomeAccount) || empty($feeAccount))) {
    $status  = 'error';
    $summary = 'Stripe sync configuration error.';
    $details = implode("\n", $errors);

    $logger->finish($logId, $status, $summary, $details);
    send_sync_email_if_needed($pdo, $config, 'Stripe', $status, $summary, $details);

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>PCO → QBO Stripe Sync</title>
        <style>
            body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 2rem; }
            .error { padding: 0.75rem 1rem; background: #fee; border: 1px solid #f99; margin-bottom: 1rem; }
        </style>
    </head>
    <body>
    <h1>PCO → QBO Stripe Sync</h1>
    <div class="error">
        <strong>Stripe sync could not start due to configuration errors:</strong>
        <ul>
            <?php foreach ($errors as $err): ?>
                <li><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <p><a href="index.php">&larr; Back to dashboard</a></p>
    </body>
    </html>
    <?php
    exit;
}

// ---------------------------------------------------------------------------
// Fetch Stripe donations and build QBO deposits
// ---------------------------------------------------------------------------

$windowEnd = $nowUtc;

try {
    $donations = get_stripe_donations($pcoConfig, $sinceUtc, $windowEnd);
} catch (Throwable $e) {
    $errors[]   = 'Error fetching Stripe donations from PCO: ' . $e->getMessage();
    $donations  = [];
}

$totalDonations = count($donations);

// Group donations by (date, location)
$groups = [];  // key = "Y-m-d|locationKey"

foreach ($donations as $donation) {
    /** @var DateTimeImmutable $completedAt */
    $completedAt = $donation['completed_at'];
    $dateStr     = $completedAt->format('Y-m-d');
    $designations = $donation['designations'] ?? [];

    foreach ($designations as $des) {
        $fundId       = (string)($des['fund_id'] ?? '');
        $amountCents  = (int)($des['amount_cents'] ?? 0);
        $feeCents     = (int)($des['fee_cents'] ?? 0);

        if ($fundId === '' || $amountCents === 0) {
            continue;
        }

        $mapping   = $fundMappings[$fundId] ?? [];
        $locName   = trim((string)($mapping['qbo_location_name'] ?? ''));
        $className = trim((string)($mapping['qbo_class_name'] ?? ''));

        $fundName = (string)(
            $mapping['pco_fund_name']
            ?? $mapping['fund_name']
            ?? ('Fund ' . $fundId)
        );

        $locKey = $locName !== '' ? $locName : '__NO_LOCATION__';
        $groupKey = $dateStr . '|' . $locKey;

        if (!isset($groups[$groupKey])) {
            $groups[$groupKey] = [
                'date'            => $dateStr,
                'location_name'   => $locName,
                'funds'           => [],
                'total_gross_cents' => 0,
                'total_fee_cents'   => 0,
                'donation_ids'    => [],
            ];
        }

        if (!isset($groups[$groupKey]['funds'][$fundId])) {
            $groups[$groupKey]['funds'][$fundId] = [
                'pco_fund_id'    => $fundId,
                'pco_fund_name'  => $fundName,
                'qbo_class_name' => $className,
                'gross_cents'    => 0,
                'fee_cents'      => 0,
            ];
        }

        $groups[$groupKey]['funds'][$fundId]['gross_cents'] += $amountCents;
        $groups[$groupKey]['funds'][$fundId]['fee_cents']   += $feeCents;
        $groups[$groupKey]['total_gross_cents']             += $amountCents;
        $groups[$groupKey]['total_fee_cents']               += $feeCents;

        $groups[$groupKey]['donation_ids'][] = $donation['id'];
    }
}

// Cache for QBO Departments (Locations)
$deptCache = [];

// Create deposits per group
foreach ($groups as $groupKey => $group) {
    $dateStr   = $group['date'];
    $locName   = $group['location_name'];
    $funds     = $group['funds'];

    if (empty($funds)) {
        continue;
    }

    // Look up QBO Department (Location) if mapped
    $deptRef = null;
    if ($locName !== '') {
        if (!array_key_exists($locName, $deptCache)) {
            try {
                $deptObj = $qbo->getDepartmentByName($locName);
                if (!$deptObj) {
                    $errors[] = "Could not find QBO Location (Department) for '{$locName}' (Stripe sync).";
                    $deptCache[$locName] = null;
                } else {
                    $deptCache[$locName] = [
                        'value' => (string)$deptObj['Id'],
                    ];
                }
            } catch (Throwable $e) {
                $errors[] = 'Error looking up QBO Location (Department) "' . $locName .
                    '" in Stripe sync: ' . $e->getMessage();
                $deptCache[$locName] = null;
            }
        }
        $deptRef = $deptCache[$locName];
    }

    $lines = [];

    foreach ($funds as $fundRow) {
        $fundName  = $fundRow['pco_fund_name'];
        $className = $fundRow['qbo_class_name'];

        // Look up QBO Class per fund, if mapped
        $classId = null;
        if ($className !== '') {
            try {
                $classObj = $qbo->getClassByName($className);
                if (!$classObj) {
                    $errors[] = "Could not find QBO Class for fund '{$fundName}' in Stripe sync: {$className}";
                } else {
                    $classId = $classObj['Id'] ?? null;
                }
            } catch (Throwable $e) {
                $errors[] = 'Error looking up QBO Class for fund ' . $fundName .
                    ' in Stripe sync: ' . $e->getMessage();
            }
        }

        $gross = round($fundRow['gross_cents'] / 100.0, 2);
        $fees  = round($fundRow['fee_cents'] / 100.0, 2); // usually negative

        if ($gross == 0.0 && $fees == 0.0) {
            continue;
        }

        // Income line
        if ($gross != 0.0) {
            $line = [
                'Amount'     => $gross,
                'DetailType' => 'DepositLineDetail',
                'DepositLineDetail' => [
                    'AccountRef' => [
                        'value' => (string)$incomeAccount['Id'],
                        'name'  => $incomeAccount['Name'] ?? $incomeAccountName,
                    ],
                ],
                'Description' => 'Stripe gross donations - ' . $fundName . ' (' . $dateStr . ')',
            ];

            if ($classId) {
                $line['DepositLineDetail']['ClassRef'] = [
                    'value' => (string)$classId,
                ];
            }

            $lines[] = $line;
        }

        // Fee line (negative)
        if ($fees != 0.0) {
            $line = [
                'Amount'     => $fees, // negative reduces deposit
                'DetailType' => 'DepositLineDetail',
                'DepositLineDetail' => [
                    'AccountRef' => [
                        'value' => (string)$feeAccount['Id'],
                        'name'  => $feeAccount['Name'] ?? $feeAccountName,
                    ],
                ],
                'Description' => 'Stripe fees - ' . $fundName . ' (' . $dateStr . ')',
            ];

            if ($classId) {
                $line['DepositLineDetail']['ClassRef'] = [
                    'value' => (string)$classId,
                ];
            }

            $lines[] = $line;
        }
    }

    if (empty($lines)) {
        $errors[] = "No lines built for Stripe group {$groupKey}, skipping deposit.";
        continue;
    }

    $totalGross = $group['total_gross_cents'] / 100.0;
    $totalFees  = $group['total_fee_cents'] / 100.0;
    $net        = $totalGross + $totalFees;

    $uniqueDonationIds = array_unique($group['donation_ids']);

    $memoParts = [
        'Stripe donations ' . $dateStr,
        'Donations: ' . count($uniqueDonationIds),
        'Gross: ' . number_format($totalGross, 2),
        'Fees: ' . number_format($totalFees, 2),
        'Net: ' . number_format($net, 2),
    ];
    if ($locName !== '') {
        $memoParts[] = 'Location: ' . $locName;
    }

    $deposit = [
        'TxnDate' => $dateStr,
        'PrivateNote' => implode(' | ', $memoParts),
        'DepositToAccountRef' => [
            'value' => (string)$bankAccount['Id'],
            'name'  => $bankAccount['Name'] ?? $depositBankName,
        ],
        'Line' => $lines,
    ];

    if ($deptRef !== null) {
        $deposit['DepartmentRef'] = $deptRef;
    }

    try {
        $resp = $qbo->createDeposit($deposit);
        $dep  = $resp['Deposit'] ?? $resp;

        $createdDeposits[] = [
            'date'          => $dateStr,
            'location_name' => $locName,
            'total_gross'   => $totalGross,
            'total_fees'    => $totalFees,
            'deposit'       => $dep,
        ];
    } catch (Throwable $e) {
        $errors[] = 'Error creating QBO Deposit for Stripe ' . $groupKey . ': ' . $e->getMessage();
    }
}

// Move the Stripe sync window forward
set_setting($pdo, 'last_completed_at', $windowEnd->format(DateTimeInterface::ATOM));

// Determine log status
if (empty($errors)) {
    $status = 'success';
} elseif (!empty($createdDeposits)) {
    $status = 'partial';
} else {
    $status = 'error';
}

$summary = sprintf(
    'Stripe: processed %d donations; created %d deposits.',
    (int)$totalDonations,
    count($createdDeposits)
);
$details = empty($errors) ? null : implode("\n", $errors);

$logger->finish($logId, $status, $summary, $details);
send_sync_email_if_needed($pdo, $config, 'Stripe', $status, $summary, $details);

// Send email notification on error or partial status
$notificationEmail = get_setting($pdo, 'notification_email');
if ($notificationEmail && in_array($status, ['error', 'partial'], true)) {
    $from   = $config['mail']['from'] ?? null;
    $mailer = new Mailer($from);

    $subject = '[PCO→QBO] Stripe sync ' . strtoupper($status);
    $bodyLines = [
        'Stripe sync run on ' . $nowUtc->format('Y-m-d H:i:s T'),
        '',
        'Status: ' . $status,
        $summary,
    ];
    if (!empty($details)) {
        $bodyLines[] = '';
        $bodyLines[] = 'Details:';
        $bodyLines[] = $details;
    }

    $mailer->send($notificationEmail, $subject, implode("\n", $bodyLines));
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PCO → QBO Stripe Sync</title>
    <style>
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 2rem; }
        .muted { font-size: 0.9rem; color: #666; }
        .ok    { padding: 0.75rem 1rem; background: #e6ffed; border: 1px solid #7dd47d; margin-bottom: 1rem; }
        .error { padding: 0.75rem 1rem; background: #fee; border: 1px solid #f99; margin-bottom: 1rem; }
        table { border-collapse: collapse; margin-top: 1rem; }
        th, td { border: 1px solid #ddd; padding: 0.35rem 0.5rem; text-align: left; font-size: 0.9rem; }
        th { background: #f7f7f7; }
    </style>
</head>
<body>
<h1>PCO → QBO Stripe Sync</h1>

<?php if (!empty($errors)): ?>
    <div class="error">
        <strong>Sync completed with errors.</strong>
        <ul>
            <?php foreach ($errors as $err): ?>
                <li><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php else: ?>
    <div class="ok">
        <strong>Stripe sync completed without errors.</strong>
    </div>
<?php endif; ?>

<p>Total Stripe donations processed (within window): <strong><?= (int)$totalDonations ?></strong></p>
<p>Deposits created in QBO: <strong><?= count($createdDeposits) ?></strong></p>

<h2>Deposits created</h2>
<?php if (empty($createdDeposits)): ?>
    <p>No Stripe donations in this window produced deposits.</p>
<?php else: ?>
    <table>
        <thead>
        <tr>
            <th>Date</th>
            <th>Location</th>
            <th>QBO Deposit (Id/DocNumber)</th>
            <th>Gross</th>
            <th>Fees</th>
            <th>Net</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($createdDeposits as $cd): ?>
            <?php
            $dep = $cd['deposit'] ?? [];
            $gross = (float)$cd['total_gross'];
            $fees  = (float)$cd['total_fees'];
            $net   = $gross + $fees;
            ?>
            <tr>
                <td><?= htmlspecialchars((string)$cd['date'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($cd['location_name'] ?: '(no location)', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)($dep['DocNumber'] ?? $dep['Id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td>$<?= number_format($gross, 2) ?></td>
                <td>$<?= number_format($fees, 2) ?></td>
                <td>$<?= number_format($net, 2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<h2>Window used</h2>
<p class="muted">
    From <strong><?= htmlspecialchars($sinceUtc->format('Y-m-d H:i:s T'), ENT_QUOTES, 'UTF-8') ?></strong>
    to <strong><?= htmlspecialchars($windowEnd->format('Y-m-d H:i:s T'), ENT_QUOTES, 'UTF-8') ?></strong>
    (based on <code>Donation.completed_at</code>).
</p>

<p style="margin-top:1.5rem;"><a href="index.php">&larr; Back to dashboard</a></p>

</body>
</html>
