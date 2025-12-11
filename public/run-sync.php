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

// -----------------------------------------------------------------------------
// Bootstrap DB + clients
// -----------------------------------------------------------------------------

try {
    $db  = Db::getInstance($config['db']);
    $pdo = $db->getConnection();
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
    echo '<h1>PCO client error</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

try {
    $qbo = new QboClient($pdo, $config);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>QuickBooks client error</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    echo '<p><a href="index.php">&larr; Back to dashboard</a></p>';
    exit;
}

$service = new SyncService($pdo, $pco);

$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));

// --- Determine sync window ---------------------------------------------------

$lastSyncStr = get_setting($pdo, 'last_completed_at');

if ($lastSyncStr) {
    try {
        $sinceUtc = new DateTimeImmutable($lastSyncStr);
    } catch (Throwable $e) {
        $sinceUtc = $nowUtc;
    }
} else {
    // First-ever run: set since = now so we don't backfill history
    $sinceUtc = $nowUtc;
}

// Build preview (fund-level totals) for this window
$preview = $service->buildDepositPreview($sinceUtc, $nowUtc);

// Prepare logging (best-effort)
$donationsCount = isset($preview['donations_count']) ? (int)$preview['donations_count'] : 0;
$depositsCount  = 0;
$logId          = null;

try {
    $logId = SyncLogger::start($pdo, $sinceUtc, $nowUtc);
} catch (Throwable $e) {
    // Ignore logging failures; sync should still proceed
    $logId = null;
}

// If nothing to sync, just advance last_completed_at and exit.
if (empty($preview['funds'])) {
    if ($logId !== null) {
        try {
            SyncLogger::finish(
                $pdo,
                $logId,
                'success',
                $donationsCount,
                0,
                'No eligible Stripe donations in this window.'
            );
        } catch (Throwable $e) {
            // Ignore logging errors in "no data" case
        }
    }
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

// --- Look up QBO accounts ----------------------------------------------------

$depositBankName = get_setting($pdo, 'deposit_bank_account_name')
    ?? 'TRINITY 2000 CHECKING';

$incomeAccountName = get_setting($pdo, 'income_account_name')
    ?? 'OPERATING INCOME:WEEKLY OFFERINGS:PLEDGES';

$stripeFeeAccountName = get_setting($pdo, 'stripe_fee_account_name')
    ?? 'OPERATING EXPENSES:MINISTRY EXPENSES:PROCESSING FEES';

$errors = [];
$createdDeposits = [];

try {
    // Bank account by simple name
    $bankAccount = $qbo->getAccountByName($depositBankName, 'Bank');
    if (!$bankAccount) {
        $errors[] = 'Could not find QBO bank account named "' . $depositBankName . '"';
    }

    // Income account
    $incomeAccount = $qbo->getAccountByName($incomeAccountName, 'Income');
    if (!$incomeAccount) {
        $errors[] = 'Could not find QBO income account named "' . $incomeAccountName . '"';
    }

    // Stripe fee expense account
    $feeAccount = $qbo->getAccountByName($stripeFeeAccountName, 'Expense');
    if (!$feeAccount) {
        $errors[] = 'Could not find QBO expense account named "' . $stripeFeeAccountName . '"';
    }

    if (!empty($errors)) {
        throw new RuntimeException('One or more required QBO accounts are missing.');
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

        $lines = [];

        // Add fund lines (gross and fee) for each PCO fund in this Location
        foreach ($group['funds'] as $fundRow) {
            $fundName  = $fundRow['pco_fund_name'];
            $className = $fundRow['qbo_class_name'];

            // Look up QBO Class per fund, if mapped
            $classId = null;
            if ($className) {
                try {
                    $classObj = $qbo->getClassByName($className);
                    if (!$classObj) {
                        $errors[] = "Could not find QBO Class for fund '{$fundName}': {$className}";
                        // Skip just this fund; other funds/locations can still sync
                        continue;
                    }
                    $classId = $classObj['Id'] ?? null;
                } catch (Throwable $e) {
                    $errors[] = 'Error looking up Class for fund ' . $fundName . ': ' . $e->getMessage();
                    continue;
                }
            }

            // Gross income line
            $gross = round((float)$fundRow['gross'], 2);
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
                    'Description' => $fundName . ' gross giving',
                ];
                if ($classId) {
                    $line['DepositLineDetail']['ClassRef'] = [
                        'value' => (string)$classId,
                    ];
                }
                $lines[] = $line;
            }

            // Stripe fee line (negative amount)
            $fee = round((float)$fundRow['fee'], 2);
            if ($fee != 0.0) {
                $feeLine = [
                    'Amount'     => $fee,
                    'DetailType' => 'DepositLineDetail',
                    'DepositLineDetail' => [
                        'AccountRef' => [
                            'value' => (string)$feeAccount['Id'],
                            'name'  => $feeAccount['Name'] ?? $stripeFeeAccountName,
                        ],
                    ],
                    'Description' => $fundName . ' Stripe fees',
                ];
                if ($classId) {
                    $feeLine['DepositLineDetail']['ClassRef'] = [
                        'value' => (string)$classId,
                    ];
                }
                $lines[] = $feeLine;
            }
        }

        if (empty($lines)) {
            $errors[] = "No lines built for Location '" . ($locName ?: '(no location)') . "', skipping deposit.";
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

        // Attach LocationRef at the Txn level if we have a Location
        if ($locName) {
            try {
                $locObj = $qbo->getLocationByName($locName);
                if (!$locObj) {
                    $errors[] = 'Could not find QBO Location named "' . $locName . '"';
                    // We still create the deposit without a Location, but record the error
                } else {
                    $deposit['TxnLocationRef'] = [
                        'value' => (string)$locObj['Id'],
                        'name'  => $locObj['Name'] ?? $locName,
                    ];
                }
            } catch (Throwable $e) {
                $errors[] = 'Error looking up QBO Location "' . $locName . '": ' . $e->getMessage();
            }
        }

        // Send to QBO, handling any API errors but continuing other Locations
        try {
            $created = $qbo->createDeposit($deposit);
            $createdDeposits[] = [
                'location'      => $locName,
                'TotalAmt'      => $created['TotalAmt'] ?? null,
                'DocNumber'     => $created['DocNumber'] ?? null,
                'TxnDate'       => $created['TxnDate'] ?? null,
                'qbo_deposit'   => $created,
                'group_totals'  => $group,
            ];
        } catch (Throwable $e) {
            $errors[] = 'Error creating QBO Deposit for Location "' . ($locName ?: '(no location)') . '": ' . $e->getMessage();
        }
    }
}

// Only move the sync window forward if everything succeeded and we actually created deposits
if (empty($errors) && !empty($createdDeposits)) {
    set_setting($pdo, 'last_completed_at', $preview['until']->format(DateTimeInterface::ATOM));
}

$depositsCount = count($createdDeposits);

// --- Finish logging and optionally send error email --------------------------

$status  = !empty($errors) ? 'error' : 'success';
$message = !empty($errors) ? implode("\n", $errors) : null;

if ($logId !== null) {
    try {
        SyncLogger::finish(
            $pdo,
            $logId,
            $status,
            $donationsCount,
            $depositsCount,
            $message
        );
    } catch (Throwable $e) {
        // Ignore logging failures; sync result page will still show details
    }
}

// Email notification on error, if configured
if ($status === 'error') {
    $notificationEmail = get_setting($pdo, 'notification_email');
    if ($notificationEmail && filter_var($notificationEmail, FILTER_VALIDATE_EMAIL)) {
        $subject = 'QBO/PCO Sync Error';
        $bodyLines = [
            'An error occurred during the QBO/PCO sync.',
            '',
            'Window:',
            '  From: ' . $sinceUtc->format('Y-m-d H:i:s') . ' UTC',
            '  To:   ' . $nowUtc->format('Y-m-d H:i:s') . ' UTC',
            '',
            'Donations processed (preview count): ' . $donationsCount,
            'Deposits created: ' . $depositsCount,
            '',
            'Errors:',
            $message ?? '(none)',
            '',
            '--',
            'This email was generated by the QBO/PCO sync app.',
        ];
        $body = implode("\r\n", $bodyLines);

        // Basic mail(); on many shared hosts this "just works".
        // If you need a different From address, adjust the header below.
        $headers = 'From: no-reply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost');

        @mail($notificationEmail, $subject, $body, $headers);
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PCO &rarr; QBO Sync Result</title>
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
<h1>PCO &rarr; QBO Sync Result</h1>

<?php if (!empty($errors)): ?>
    <div class="error">
        <strong>Sync encountered errors.</strong>
        <ul>
            <?php foreach ($errors as $err): ?>
                <li><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php else: ?>
    <div class="ok">
        Sync completed successfully.
    </div>
<?php endif; ?>

<?php if (!empty($createdDeposits)): ?>
    <h2>Created Deposits</h2>
    <table>
        <thead>
        <tr>
            <th>Location</th>
            <th>TxnDate</th>
            <th>DocNumber</th>
            <th>QBO TotalAmt</th>
            <th>Group Gross</th>
            <th>Group Stripe Fees</th>
            <th>Group Net</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($createdDeposits as $cd): ?>
            <?php
            $loc = $cd['location'] ?: '(no location)';
            $dep = $cd['qbo_deposit'] ?? [];
            $group = $cd['group_totals'] ?? [];
            ?>
            <tr>
                <td><?= htmlspecialchars($loc, ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)($dep['TxnDate'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)($dep['DocNumber'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td>$<?= htmlspecialchars((string)($dep['TotalAmt'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td>$<?= number_format($group['total_gross'], 2) ?></td>
                <td>$<?= number_format($group['total_fee'], 2) ?></td>
                <td>$<?= number_format($group['total_net'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<p style="margin-top:1.5rem;"><a href="index.php">&larr; Back to dashboard</a></p>

</body>
</html>
