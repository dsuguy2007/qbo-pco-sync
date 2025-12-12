<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/PcoClient.php';
require_once __DIR__ . '/../src/Auth.php';
Auth::requireLogin();

$error = null;
$fundTotals = [];
$unmapped   = [];
$donationsEvaluated = 0;
$batchesEvaluated   = 0;
$grossTotal = 0.0;
$feeTotal   = 0.0;

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

$days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
if ($days < 1) {
    $days = 1;
}

$nowUtc   = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$sinceUtc = $nowUtc->sub(new DateInterval('P' . $days . 'D'));

try {
    $db  = Db::getInstance($config['db']);
    $pdo = $db->getConnection();
    $displayTz = get_display_timezone($pdo);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Database error</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

// Load fund mappings to reuse class/location where possible
$fundMappings = [];
try {
    $stmt = $pdo->query('SELECT * FROM fund_mappings');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $fid = (string)($row['pco_fund_id'] ?? '');
        if ($fid !== '') {
            $fundMappings[$fid] = $row;
        }
    }
} catch (Throwable $e) {
    // ignore mapping errors in preview
}

try {
    $pco      = new PcoClient($config);
    $resp     = $pco->listRegistrationPayments(['include' => 'event,person,registration', 'per_page' => 100]);
    $payments = $resp['data'] ?? [];
    $included = $resp['included'] ?? [];

    // Build quick lookup for included
    $lookup = [];
    foreach ($included as $inc) {
        $type = $inc['type'] ?? '';
        $id   = (string)($inc['id'] ?? '');
        if ($type && $id) {
            $lookup[$type][$id] = $inc['attributes'] ?? [];
        }
    }

    foreach ($payments as $pay) {
        $attrs      = $pay['attributes'] ?? [];
        $occurredAt = $attrs['created_at'] ?? ($attrs['paid_at'] ?? null);
        if (!$occurredAt) {
            continue;
        }
        try {
            $paidAtDt = new DateTimeImmutable($occurredAt);
        } catch (Throwable $e) {
            continue;
        }
        if ($paidAtDt < $sinceUtc || $paidAtDt > $nowUtc) {
            continue;
        }

        $donationsEvaluated++;
        $grossCents = (int)($attrs['amount_cents'] ?? 0);
        $feeCents   = (int)($attrs['stripe_fee_cents'] ?? 0);

        $gross = $grossCents / 100.0;
        $fee   = $feeCents / 100.0;
        $net   = $gross - $fee;

        $eventId   = $pay['relationships']['event']['data']['id'] ?? null;
        $eventName = trim((string)($attrs['event_name'] ?? ''));
        if ($eventName === '') {
            $eventName = 'Event ' . ($eventId ?? '');
        }

        // Group by event for preview
        $key = $eventId ?: 'unknown';
        if (!isset($fundTotals[$key])) {
            $fundTotals[$key] = [
                'event_id'   => $eventId,
                'event_name' => $eventName,
                'gross'      => 0.0,
                'fee'        => 0.0,
                'net'        => 0.0,
                'count'      => 0,
            ];
        }
        $fundTotals[$key]['gross'] += $gross;
        $fundTotals[$key]['fee']   += $fee;
        $fundTotals[$key]['net']   += $net;
        $fundTotals[$key]['count']++;

        $grossTotal += $gross;
        $feeTotal   += $fee;
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Registrations Payments Preview</title>
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
        body {
            font-family: 'Manrope', 'Segoe UI', sans-serif;
            margin: 0;
            background: radial-gradient(circle at 15% 20%, rgba(46, 168, 255, 0.12), transparent 25%),
                        radial-gradient(circle at 85% 10%, rgba(57, 217, 138, 0.15), transparent 22%),
                        radial-gradient(circle at 70% 70%, rgba(242, 201, 76, 0.08), transparent 30%),
                        var(--bg);
            color: var(--text);
            min-height: 100vh;
        }
        * { box-sizing: border-box; }
        .page { max-width: 1200px; margin: 0 auto; padding: 2.4rem 1.25rem 3rem; }
        .hero {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 1.4rem 1.5rem;
            box-shadow: 0 10px 40px rgba(0,0,0,0.25);
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .hero::after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 30% 40%, rgba(46,168,255,0.15), transparent 35%),
                        radial-gradient(circle at 80% 10%, rgba(57,217,138,0.12), transparent 35%);
            pointer-events: none;
        }
        .hero > * { position: relative; z-index: 1; }
        .eyebrow { letter-spacing: 0.05em; text-transform: uppercase; color: var(--muted); font-size: 0.8rem; margin-bottom: 0.25rem; }
        h1 { margin: 0 0 0.35rem; font-size: 2rem; letter-spacing: -0.01em; }
        .lede { color: var(--muted); margin: 0; max-width: 64ch; line-height: 1.6; }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.65rem 1rem;
            background: linear-gradient(135deg, var(--accent), var(--accent-strong));
            color: #0b1324;
            font-weight: 700;
            text-decoration: none;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.08);
            box-shadow: 0 10px 25px rgba(13,122,223,0.35);
            transition: transform 120ms ease, box-shadow 120ms ease, background 150ms ease;
        }
        .btn.secondary { background: transparent; color: var(--text); border: 1px solid var(--border); box-shadow: none; }
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 0.75rem;
            margin-top: 0.6rem;
        }
        .metric {
            padding: 0.95rem 1rem;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: rgba(255,255,255,0.02);
        }
        .metric .label { color: var(--muted); font-size: 0.9rem; margin-bottom: 0.2rem; }
        .metric .value { font-size: 1.25rem; font-weight: 700; }
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 1.2rem 1.25rem;
            box-shadow: 0 8px 30px rgba(0,0,0,0.18);
            margin-top: 1rem;
        }
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 0.85rem;
        }
        .section-title { margin: 0; font-size: 1.1rem; letter-spacing: -0.01em; }
        .section-sub { margin: 0; color: var(--muted); font-size: 0.95rem; }
        .filters { display: inline-flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; }
        .filters input[type="number"] {
            width: 80px;
            padding: 0.55rem 0.6rem;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.14);
            background: rgba(255,255,255,0.04);
            color: var(--text);
            font-size: 1rem;
        }
        .table-wrap { overflow: auto; border-radius: 10px; border: 1px solid var(--border); margin-top: 0.75rem; }
        table { width: 100%; border-collapse: collapse; min-width: 720px; background: rgba(255,255,255,0.01); }
        th, td { border-bottom: 1px solid rgba(255,255,255,0.08); padding: 0.65rem 0.75rem; vertical-align: top; font-size: 0.95rem; text-align: left; }
        th { color: var(--muted); font-weight: 700; background: rgba(255,255,255,0.03); position: sticky; top: 0; backdrop-filter: blur(8px); z-index: 1; }
        tr:hover td { background: rgba(46,168,255,0.03); }
        .muted { color: var(--muted); font-size: 0.95rem; line-height: 1.5; }
        .footer { margin-top: 1.4rem; text-align: center; color: var(--muted); font-size: 0.9rem; }
        .footer a { color: var(--accent); text-decoration: none; }
        .footer a:hover { color: #dff1ff; }
        @media (max-width: 720px) {
            .hero { padding: 1.2rem 1.1rem; }
            .section-header { align-items: flex-start; }
            .btn.secondary { width: 100%; justify-content: center; }
            .filters { width: 100%; justify-content: space-between; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="hero">
        <div>
            <div class="eyebrow">Preview</div>
            <h1>Registrations payments</h1>
            <p class="lede">Inspect Planning Center Registrations payments before syncing to QuickBooks.</p>
        </div>
        <a class="btn secondary" href="index.php">&larr; Back to dashboard</a>
    </div>

    <div class="card" style="margin-top: 1.1rem;">
        <div class="section-header">
            <div>
                <p class="section-title">Window and filters</p>
                <p class="section-sub">created_at within the selected range (legacy Registrations API version).</p>
            </div>
            <form method="get" class="filters">
                <label for="days">Days back</label>
                <input type="number" min="1" id="days" name="days" value="<?= htmlspecialchars((string)$days, ENT_QUOTES, 'UTF-8') ?>">
                <button class="btn secondary" type="submit">Refresh</button>
            </form>
        </div>

        <div class="metrics-grid">
            <div class="metric">
                <div class="label">Window start (<?= htmlspecialchars($displayTz->getName(), ENT_QUOTES, 'UTF-8') ?>)</div>
                <div class="value"><?= htmlspecialchars(fmt_dt($sinceUtc, $displayTz), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="metric">
                <div class="label">Window end (<?= htmlspecialchars($displayTz->getName(), ENT_QUOTES, 'UTF-8') ?>)</div>
                <div class="value"><?= htmlspecialchars(fmt_dt($nowUtc, $displayTz), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="metric">
                <div class="label">Payments evaluated</div>
                <div class="value"><?= (int)$donationsEvaluated ?></div>
            </div>
            <div class="metric">
                <div class="label">Total gross</div>
                <div class="value">$<?= number_format($grossTotal, 2) ?></div>
            </div>
            <div class="metric">
                <div class="label">Total fees</div>
                <div class="value">$<?= number_format($feeTotal, 2) ?></div>
            </div>
            <div class="metric">
                <div class="label">Total net</div>
                <div class="value">$<?= number_format($grossTotal - $feeTotal, 2) ?></div>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="card">
            <p class="muted">Error loading payments: <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
            <p class="muted">
                If you see a 404/Not Found, your Planning Center token may not have Registrations API access.
                Verify your personal access token includes Registrations scope and that the Registrations product is enabled in your PCO account.
            </p>
        </div>
    <?php elseif (empty($fundTotals)): ?>
        <div class="card">
            <p class="muted">No registrations payments found in this window.</p>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="section-header">
                <div>
                    <p class="section-title">Per-event breakdown</p>
                    <p class="section-sub">Totals that will be booked into QBO.</p>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Event</th>
                        <th>Payments</th>
                        <th>Gross</th>
                        <th>Fees</th>
                        <th>Net</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($fundTotals as $row): ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars((string)($row['event_name'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8') ?><br>
                                <span class="muted">ID: <?= htmlspecialchars((string)($row['event_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                            </td>
                            <td><?= (int)$row['count'] ?></td>
                            <td>$<?= number_format($row['gross'], 2) ?></td>
                            <td>$<?= number_format($row['fee'], 2) ?></td>
                            <td>$<?= number_format($row['net'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                    <tr>
                        <th>Total</th>
                        <th><?= array_sum(array_column($fundTotals, 'count')) ?></th>
                        <th>$<?= number_format($grossTotal, 2) ?></th>
                        <th>$<?= number_format($feeTotal, 2) ?></th>
                        <th>$<?= number_format($grossTotal - $feeTotal, 2) ?></th>
                    </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <div class="footer">
        &copy; <?= date('Y') ?> Rev. Tommy Sheppard • <a href="help.php">Help</a>
    </div>
</div>
</body>
</html>
