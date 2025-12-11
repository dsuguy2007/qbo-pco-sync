<?php
declare(strict_types=1);



$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/PcoClient.php';
require_once __DIR__ . '/../src/SyncService.php';
require_once __DIR__ . '/../src/Auth.php';
Auth::requireLogin();
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

$service = new SyncService($pdo, $pco);

// days back to look based on completed_at
$days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
if ($days < 1) {
    $days = 1;
}

$nowUtc   = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$sinceUtc = $nowUtc->sub(new DateInterval('P' . $days . 'D'));

$preview = $service->buildDepositPreview($sinceUtc, $nowUtc);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PCO → QBO Sync Preview (completed_at)</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 2rem;
        }
        table {
            border-collapse: collapse;
            width: 100%;
            max-width: 900px;
        }
        th, td {
            border: 1px solid #ccc;
            padding: 0.4rem 0.6rem;
            font-size: 0.9rem;
            text-align: left;
        }
        th {
            background: #f5f5f5;
        }
        .small {
            font-size: 0.8rem;
            color: #666;
        }
        .summary {
            margin: 1rem 0;
        }
        .muted {
            color: #666;
            font-size: 0.85rem;
        }
        .top-nav a {
            display: inline-block;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>

<div class="top-nav">
    <a href="index.php">&larr; Back to main dashboard</a>
</div>

<h1>PCO → QBO Sync Preview</h1>

<p class="small">
    Showing online (card/ACH) donations with <strong>payment_status = succeeded</strong> and
    <strong>completed_at</strong> in the last
    <strong><?= htmlspecialchars((string)$days, ENT_QUOTES, 'UTF-8') ?></strong> day(s).
</p>

<div class="summary">
    <p>
        Completed_at window (UTC):<br>
        <strong>From:</strong>
        <?= htmlspecialchars($preview['since']->format('Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8') ?><br>
        <strong>To:</strong>
        <?= htmlspecialchars($preview['until']->format('Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8') ?>
    </p>
    <p>
        Donations evaluated: <strong><?= (int)$preview['donation_count'] ?></strong><br>
        Donations included in totals: <strong><?= (int)$preview['processed_donations'] ?></strong><br>
        Offline (cash/check) donations skipped: <strong><?= (int)$preview['skipped_offline'] ?></strong>
    </p>
    <p>
        Total gross (all funds): <strong>$<?= number_format($preview['total_gross'], 2) ?></strong><br>
        Total Stripe fees (all funds): <strong>$<?= number_format($preview['total_fee'], 2) ?></strong><br>
        Total net (expected Stripe payout): <strong>$<?= number_format($preview['total_net'], 2) ?></strong>
    </p>
</div>

<?php if (empty($preview['funds'])): ?>
    <p><em>No eligible Stripe donations found in this window.</em></p>
<?php else: ?>
    <h2>Per-fund breakdown (future QBO Deposit lines)</h2>
    <table>
        <thead>
        <tr>
            <th>PCO Fund</th>
            <th>QBO Class</th>
            <th>QBO Location</th>
            <th>Gross</th>
            <th>Stripe Fee</th>
            <th>Net</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($preview['funds'] as $row): ?>
            <tr>
                <td>
                    <?= htmlspecialchars($row['pco_fund_name'], ENT_QUOTES, 'UTF-8') ?><br>
                    <span class="small">Fund ID: <?= htmlspecialchars((string)$row['pco_fund_id'], ENT_QUOTES, 'UTF-8') ?></span>
                </td>
                <td><?= htmlspecialchars((string)$row['qbo_class_name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)$row['qbo_location_name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>$<?= number_format($row['gross'], 2) ?></td>
                <td>$<?= number_format($row['fee'], 2) ?></td>
                <td>$<?= number_format($row['net'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
        <tr>
            <th colspan="3">Totals</th>
            <th>$<?= number_format($preview['total_gross'], 2) ?></th>
            <th>$<?= number_format($preview['total_fee'], 2) ?></th>
            <th>$<?= number_format($preview['total_net'], 2) ?></th>
        </tr>
        </tfoot>
    </table>
<?php endif; ?>

<?php if (!empty($preview['skipped_unmapped'])): ?>
    <h2>Skipped donation pieces (unmapped funds or missing designations)</h2>
    <p class="muted">
        These donations (or parts of donations) were ignored because the fund isn’t mapped
        or designations were missing. Update your Fund → Class/Location mappings if you want
        them included.
    </p>
    <table>
        <thead>
        <tr>
            <th>Donation ID</th>
            <th>Reason</th>
            <th>Fund ID</th>
            <th>Amount (cents)</th>
            <th>Payment Method</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($preview['skipped_unmapped'] as $skip): ?>
            <tr>
                <td><?= htmlspecialchars((string)($skip['donation_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)($skip['reason'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)($skip['fund_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)($skip['amount_cents'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)($skip['payment_method'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<p class="muted" style="margin-top: 1.5rem;">
    Once this preview matches your Stripe payout report for the same period,
    we’ll wire these per-fund gross and fee totals into a real QuickBooks Online
    <strong>Deposit</strong>: one income line and one fee line per fund into
    TRINITY 2000 CHECKING, using your mapped Class and Location.
</p>

</body>
</html>
