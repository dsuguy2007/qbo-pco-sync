<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/QboClient.php';
require_once __DIR__ . '/../src/Auth.php';

Auth::requireLogin();

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
 * Fetch committed batches whose committed_at falls between the window.
 */
function get_committed_batches(
    array $pcoConfig,
    DateTimeImmutable $windowStart,
    DateTimeImmutable $windowEnd
): array {
    $resp = pco_request(
        $pcoConfig,
        '/giving/v2/batches',
        [
            'per_page' => 100,
            'order'    => 'committed_at',
        ]
    );

    $batches = [];

    foreach ($resp['data'] ?? [] as $batch) {
        $attrs        = $batch['attributes'] ?? [];
        $committedStr = $attrs['committed_at'] ?? null;
        if (!$committedStr) {
            // Not committed yet
            continue;
        }

        try {
            $committedAt = new DateTimeImmutable($committedStr);
        } catch (Throwable $e) {
            continue;
        }

        if ($committedAt < $windowStart || $committedAt > $windowEnd) {
            continue;
        }

        $name = $attrs['name'] ?? ($attrs['description'] ?? '');
        $batches[] = [
            'id'           => (string)$batch['id'],
            'name'         => (string)$name,
            'committed_at' => $committedAt,
        ];
    }

    return $batches;
}

/**
 * Fetch donations belonging to a specific batch, then per-donation designations.
 * We only keep check/cash donations to avoid overlapping Stripe/card flows.
 */
function get_batch_donations(array $pcoConfig, string $batchId): array
{
    // First get the donations in this batch
    $resp = pco_request(
        $pcoConfig,
        '/giving/v2/batches/' . rawurlencode($batchId) . '/donations',
        [
            'per_page' => 100,
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
        // For batch sync we only want offline giving (cash/check)
        if (!in_array($paymentMethod, ['cash', 'check'], true)) {
            continue;
        }

        // Now fetch designations for this donation via its own endpoint
        $desResp = pco_request(
            $pcoConfig,
            '/giving/v2/donations/' . rawurlencode($id) . '/designations',
            [
                'per_page' => 100,
            ]
        );

        $designations = [];
        foreach ($desResp['data'] ?? [] as $des) {
            $dAttrs = $des['attributes'] ?? [];
            $rels   = $des['relationships'] ?? [];
            $fund   = $rels['fund']['data'] ?? null;

            if (!$fund) {
                continue;
            }

            $fundId = (string)($fund['id'] ?? '');
            if ($fundId === '') {
                continue;
            }

            $designations[] = [
                'fund_id'      => $fundId,
                'amount_cents' => (int)($dAttrs['amount_cents'] ?? 0),
            ];
        }

        $donations[] = [
            'id'           => $id,
            'received_at'  => $attrs['received_at'] ?? null,
            'amount_cents' => (int)($attrs['amount_cents'] ?? 0),
            'designations' => $designations,
        ];
    }

    return $donations;
}

// ---------------------------------------------------------------------------
// Bootstrap DB / clients
// ---------------------------------------------------------------------------

try {
    $db  = Db::getInstance($config['db']);
    $pdo = $db->getConnection();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Batch sync error</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

$pcoConfig = $config['pco'] ?? [];
if (empty($pcoConfig['app_id']) || empty($pcoConfig['secret'])) {
    http_response_code(500);
    echo '<h1>PCO client error</h1>';
    echo '<p>PCO credentials are not configured. Please set pco.app_id and pco.secret in config.php.</p>';
    exit;
}

try {
    $qbo = new QboClient($pdo, $config);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>QBO client error</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

// ---------------------------------------------------------------------------
// Check settings to see if batch sync is enabled
// ---------------------------------------------------------------------------

$enableBatchSync = get_setting($pdo, 'enable_batch_sync') ?? '0';
if ($enableBatchSync !== '1') {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>PCO → QBO Batch Sync</title>
        <style>
            body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 2rem; }
            .muted { font-size: 0.9rem; color: #666; }
        </style>
    </head>
    <body>
    <h1>PCO → QBO Batch Sync</h1>
    <p>Batch sync is currently disabled in Settings.</p>
    <p class="muted">Enable "Sync committed batches" in the Settings page to use this feature.</p>
    <p><a href="settings.php">&rarr; Go to Settings</a></p>
    <p><a href="index.php">&larr; Back to dashboard</a></p>
    </body>
    </html>
    <?php
    exit;
}

// ---------------------------------------------------------------------------
// Determine sync window (based on batch committed_at)
// ---------------------------------------------------------------------------

$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));

$lastBatchStr = get_setting($pdo, 'last_batch_sync_completed_at');

if ($lastBatchStr) {
    try {
        $sinceUtc = new DateTimeImmutable($lastBatchStr);
    } catch (Throwable $e) {
        $sinceUtc = $nowUtc;
    }
} else {
    // First ever batch-sync run: initialize window and exit (no backfill).
    set_setting($pdo, 'last_batch_sync_completed_at', $nowUtc->format(DateTimeInterface::ATOM));
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>PCO → QBO Batch Sync</title>
        <style>
            body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 2rem; }
            .muted { font-size: 0.9rem; color: #666; }
        </style>
    </head>
    <body>
    <h1>PCO → QBO Batch Sync</h1>
    <p>This is the first time batch sync has been run.</p>
    <p class="muted">
        We have initialized the batch sync window at
        <strong><?= htmlspecialchars($nowUtc->format('Y-m-d H:i:s T'), ENT_QUOTES, 'UTF-8') ?></strong>.
        No historical batches were synced. Next run will pick up batches committed after this time.
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

$errors          = [];
$createdDeposits = [];
$totalDonations  = 0;

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

// If we already have fatal errors, stop before hitting external APIs further
if (!empty($errors)) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>PCO → QBO Batch Sync</title>
        <style>
            body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 2rem; }
            .error { padding: 0.75rem 1rem; background: #fee; border: 1px solid #f99; margin-bottom: 1rem; }
            .muted { font-size: 0.9rem; color: #666; }
        </style>
    </head>
    <body>
    <h1>PCO → QBO Batch Sync</h1>
    <div class="error">
        <strong>Batch sync could not start due to configuration errors:</strong>
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
// Fetch committed batches and build QBO deposits
// ---------------------------------------------------------------------------

$windowEnd = $nowUtc;

try {
    $batches = get_committed_batches($pcoConfig, $sinceUtc, $windowEnd);
} catch (Throwable $e) {
    $errors[] = 'Error fetching committed batches from PCO: ' . $e->getMessage();
    $batches  = [];
}

foreach ($batches as $batchInfo) {
    $batchId     = $batchInfo['id'];
    $batchName   = $batchInfo['name'];
    $committedAt = $batchInfo['committed_at']; // DateTimeImmutable

    // Group by Location within this batch
    $locationGroups = [];

    try {
        $donations = get_batch_donations($pcoConfig, $batchId);
    } catch (Throwable $e) {
        $errors[] = 'Error fetching donations for batch ' . $batchId . ': ' . $e->getMessage();
        continue;
    }

    foreach ($donations as $donation) {
        $totalDonations++;
        $designations = $donation['designations'] ?? [];

        foreach ($designations as $des) {
            $fundId      = (string)($des['fund_id'] ?? '');
            $amountCents = (int)($des['amount_cents'] ?? 0);

            if ($fundId === '' || $amountCents === 0) {
                continue;
            }

            $amount = $amountCents / 100.0;

            $mapping   = $fundMappings[$fundId] ?? [];
            $locName   = trim((string)($mapping['qbo_location_name'] ?? ''));
            $className = trim((string)($mapping['qbo_class_name'] ?? ''));

            $fundName = (string)(
                $mapping['pco_fund_name']
                ?? $mapping['fund_name']
                ?? ('Fund ' . $fundId)
            );

            $locKey = $locName !== '' ? $locName : '__NO_LOCATION__';

            if (!isset($locationGroups[$locKey])) {
                $locationGroups[$locKey] = [
                    'batch_id'      => $batchId,
                    'batch_name'    => $batchName,
                    'committed_at'  => $committedAt,
                    'location_name' => $locName,
                    'funds'         => [],
                    'total_gross'   => 0.0,
                ];
            }

            if (!isset($locationGroups[$locKey]['funds'][$fundId])) {
                $locationGroups[$locKey]['funds'][$fundId] = [
                    'pco_fund_id'    => $fundId,
                    'pco_fund_name'  => $fundName,
                    'qbo_class_name' => $className,
                    'gross'          => 0.0,
                ];
            }

            $locationGroups[$locKey]['funds'][$fundId]['gross'] += $amount;
            $locationGroups[$locKey]['total_gross']             += $amount;
        }
    }

    // For each (batch, location) group, build one Deposit
    foreach ($locationGroups as $locKey => $group) {
        $locName      = $group['location_name'];
        $batchName    = $group['batch_name'];
        /** @var DateTimeImmutable $committedAt */
        $committedAt  = $group['committed_at'];
        $funds        = $group['funds'];

        if (empty($funds)) {
            continue;
        }

        // Look up QBO Department (Location) if one is mapped
        $deptRef = null;
        if ($locName !== '') {
            try {
                $deptObj = $qbo->getDepartmentByName($locName);
                if (!$deptObj) {
                    $errors[] = "Could not find QBO Location (Department) for '{$locName}' (batch {$batchId}).";
                } else {
                    $deptRef = [
                        'value' => (string)$deptObj['Id'],
                    ];
                }
            } catch (Throwable $e) {
                $errors[] = 'Error looking up QBO Location (Department) "' . $locName .
                    '" for batch ' . $batchId . ': ' . $e->getMessage();
            }
        }

        $lines = [];

        // Build one income line per fund, with ClassRef per fund if mapped
        foreach ($funds as $fundRow) {
            $fundName  = $fundRow['pco_fund_name'];
            $className = $fundRow['qbo_class_name'];

            // Look up QBO Class per fund, if mapped
            $classId = null;
            if ($className !== '') {
                try {
                    $classObj = $qbo->getClassByName($className);
                    if (!$classObj) {
                        $errors[] = "Could not find QBO Class for fund '{$fundName}' (batch {$batchId}): {$className}";
                    } else {
                        $classId = $classObj['Id'] ?? null;
                    }
                } catch (Throwable $e) {
                    $errors[] = 'Error looking up QBO Class for fund ' . $fundName .
                        ' (batch ' . $batchId . '): ' . $e->getMessage();
                }
            }

            $gross = round((float)$fundRow['gross'], 2);
            if ($gross == 0.0) {
                continue;
            }

            $line = [
                'Amount'     => $gross,
                'DetailType' => 'DepositLineDetail',
                'DepositLineDetail' => [
                    'AccountRef' => [
                        'value' => (string)$incomeAccount['Id'],
                        'name'  => $incomeAccount['Name'] ?? $incomeAccountName,
                    ],
                ],
                'Description' => $fundName . ' gross donations (Batch ' . $batchId . ')',
            ];

            if ($classId) {
                $line['DepositLineDetail']['ClassRef'] = [
                    'value' => (string)$classId,
                ];
            }

            $lines[] = $line;
        }

        if (empty($lines)) {
            $errors[] = "No lines built for batch {$batchId} / Location '" . ($locName ?: '(no location)') . "', skipping deposit.";
            continue;
        }

        $memoParts = [
            'PCO Batch ' . $batchId,
        ];
        if ($batchName !== '') {
            $memoParts[] = $batchName;
        }
        $memoParts[] = 'Committed at ' . $committedAt->format('Y-m-d H:i:s');
        if ($locName !== '') {
            $memoParts[] = 'Location: ' . $locName;
        }

        $deposit = [
            // TxnDate = committed_at date
            'TxnDate' => $committedAt->format('Y-m-d'),
            'PrivateNote' => implode(' | ', $memoParts),
            'DepositToAccountRef' => [
                'value' => (string)$bankAccount['Id'],
                'name'  => $bankAccount['Name'] ?? $depositBankName,
            ],
            'Line' => $lines,
        ];

        // One DepartmentRef (Location) per Deposit
        if ($deptRef !== null) {
            $deposit['DepartmentRef'] = $deptRef;
        }

        try {
            // QboClient::createDeposit is assumed to return either the Deposit object
            // or an array containing it. We'll just stash whatever comes back.
            $resp = $qbo->createDeposit($deposit);
            $dep  = $resp['Deposit'] ?? $resp;

            $createdDeposits[] = [
                'batch_id'      => $batchId,
                'batch_name'    => $batchName,
                'location_name' => $locName,
                'total_gross'   => $group['total_gross'],
                'deposit'       => $dep,
            ];
        } catch (Throwable $e) {
            $errors[] = 'Error creating QBO Deposit for batch ' . $batchId .
                ' / Location ' . ($locName ?: '(no location)') . ': ' . $e->getMessage();
        }
    }
}

// Move the batch sync window forward to the end of this run
set_setting($pdo, 'last_batch_sync_completed_at', $windowEnd->format(DateTimeInterface::ATOM));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PCO → QBO Batch Sync</title>
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
<h1>PCO → QBO Batch Sync</h1>

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
        <strong>Batch sync completed without errors.</strong>
    </div>
<?php endif; ?>

<p>Total donations processed (cash/check, within window): <strong><?= (int)$totalDonations ?></strong></p>
<p>Deposits created in QBO: <strong><?= count($createdDeposits) ?></strong></p>

<h2>Deposits created</h2>
<?php if (empty($createdDeposits)): ?>
    <p>No committed batches in this window produced deposits.</p>
<?php else: ?>
    <table>
        <thead>
        <tr>
            <th>Batch ID</th>
            <th>Batch Name</th>
            <th>Location</th>
            <th>QBO Deposit (Id/DocNumber)</th>
            <th>Total Gross</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($createdDeposits as $cd): ?>
            <?php $dep = $cd['deposit'] ?? []; ?>
            <tr>
                <td><?= htmlspecialchars((string)$cd['batch_id'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)$cd['batch_name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($cd['location_name'] ?: '(no location)', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)($dep['DocNumber'] ?? $dep['Id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td>$<?= number_format((float)$cd['total_gross'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<h2>Window used</h2>
<p class="muted">
    From <strong><?= htmlspecialchars($sinceUtc->format('Y-m-d H:i:s T'), ENT_QUOTES, 'UTF-8') ?></strong>
    to <strong><?= htmlspecialchars($windowEnd->format('Y-m-d H:i:s T'), ENT_QUOTES, 'UTF-8') ?></strong>
    (based on <code>Batch.committed_at</code>).
</p>

<p style="margin-top:1.5rem;"><a href="index.php">&larr; Back to dashboard</a></p>

</body>
</html>
