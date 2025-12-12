<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../src/Db.php';
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

$message = null;
$error   = null;

// Handle cleanup POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cleanup_logs'])) {
    try {
        $days = isset($_POST['retention_days']) ? (int)$_POST['retention_days'] : 90;
        if ($days < 1) {
            $days = 1;
        }

        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $cutoff = $nowUtc->modify("-{$days} days")->format('Y-m-d H:i:s');

        $stmt = $pdo->prepare('DELETE FROM sync_logs WHERE finished_at IS NOT NULL AND finished_at < :cutoff');
        $stmt->execute([':cutoff' => $cutoff]);
        $deleted = $stmt->rowCount();

        $message = "Deleted {$deleted} log entr" . ($deleted === 1 ? 'y' : 'ies') . " older than {$days} day" . ($days === 1 ? '' : 's') . '.';
    } catch (Throwable $e) {
        $error = 'Error cleaning up logs: ' . $e->getMessage();
    }
}

// Fetch latest logs for display
$logs = [];
try {
    $stmt = $pdo->query('
        SELECT id, sync_type, started_at, finished_at, status, summary, details
        FROM sync_logs
        ORDER BY started_at DESC
        LIMIT 200
    ');
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $error = $error ?? ('Error loading logs: ' . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sync Logs - PCO &harr; QBO Sync</title>
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
        .page {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2.4rem 1.25rem 3rem;
        }
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
        .eyebrow {
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: var(--muted);
            font-size: 0.8rem;
            margin-bottom: 0.25rem;
        }
        h1 {
            margin: 0 0 0.35rem;
            font-size: 2rem;
            letter-spacing: -0.01em;
        }
        .lede {
            color: var(--muted);
            margin: 0;
            max-width: 64ch;
            line-height: 1.6;
        }
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
        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 14px 30px rgba(13,122,223,0.4);
        }
        .btn.secondary {
            background: transparent;
            color: var(--text);
            border: 1px solid var(--border);
            box-shadow: none;
        }
        .flash {
            margin-top: 1rem;
            padding: 0.85rem 1rem;
            border-radius: 10px;
            border: 1px solid var(--border);
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 0.75rem;
            align-items: center;
        }
        .flash.success {
            background: rgba(57, 217, 138, 0.12);
            border-color: rgba(57, 217, 138, 0.35);
        }
        .flash.error {
            background: rgba(255, 122, 122, 0.12);
            border-color: rgba(255, 122, 122, 0.35);
        }
        .flash .tag {
            background: rgba(255, 255, 255, 0.06);
            padding: 0.35rem 0.65rem;
            border-radius: 999px;
            font-size: 0.85rem;
            border: 1px solid var(--border);
        }
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
        .section-title {
            margin: 0;
            font-size: 1.1rem;
            letter-spacing: -0.01em;
        }
        .section-sub {
            margin: 0;
            color: var(--muted);
            font-size: 0.95rem;
        }
        form.cleanup-form {
            display: grid;
            gap: 0.6rem;
            align-items: center;
        }
        .cleanup-inline {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            flex-wrap: wrap;
        }
        .cleanup-inline input[type="number"] {
            width: 90px;
            padding: 0.6rem 0.65rem;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.14);
            background: rgba(255,255,255,0.04);
            color: var(--text);
        }
        .table-wrap {
            overflow: auto;
            border-radius: 10px;
            border: 1px solid var(--border);
            margin-top: 0.5rem;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
            background: rgba(255,255,255,0.01);
        }
        th, td {
            border-bottom: 1px solid rgba(255,255,255,0.08);
            padding: 0.65rem 0.75rem;
            vertical-align: top;
            font-size: 0.95rem;
            text-align: left;
        }
        th {
            color: var(--muted);
            font-weight: 700;
            background: rgba(255,255,255,0.03);
            position: sticky;
            top: 0;
            backdrop-filter: blur(8px);
            z-index: 1;
        }
        tr:hover td {
            background: rgba(46,168,255,0.03);
        }
        .pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.35rem 0.65rem;
            border-radius: 999px;
            font-size: 0.85rem;
            font-weight: 700;
            color: #0f172a;
        }
        .pill.ok { background: rgba(57, 217, 138, 0.9); }
        .pill.warn { background: rgba(242, 201, 76, 0.9); }
        .pill.error { background: rgba(255, 122, 122, 0.9); }
        .summary-text {
            color: var(--muted);
            font-size: 0.95rem;
        }
        .details {
            white-space: pre-wrap;
            max-width: 480px;
            color: var(--text);
        }
        .run-type {
            text-transform: capitalize;
        }
        .footer {
            margin-top: 1.4rem;
            text-align: center;
            color: var(--muted);
            font-size: 0.9rem;
        }
        .footer a { color: var(--accent); text-decoration: none; }
        .footer a:hover { color: #dff1ff; }
        @media (max-width: 720px) {
            .hero { padding: 1.2rem 1.1rem; }
            .section-header { align-items: flex-start; }
            .btn.secondary { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="hero">
        <div>
            <div class="eyebrow">Operations</div>
            <h1>Sync logs</h1>
            <p class="lede">Review the latest sync runs, summaries, and details, and clean up older entries.</p>
        </div>
        <a class="btn secondary" href="index.php">&larr; Back to dashboard</a>
    </div>

    <?php if ($message): ?>
        <div class="flash success">
            <span class="tag">Saved</span>
            <div><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="flash error">
            <span class="tag">Issue</span>
            <div><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="section-header">
            <div>
                <p class="section-title">Log retention</p>
                <p class="section-sub">Delete older rows from the sync_logs table.</p>
            </div>
        </div>
        <form method="post" class="cleanup-form">
            <div class="cleanup-inline">
                <label for="retention_days"><strong>Keep the last</strong></label>
                <input type="number" id="retention_days" name="retention_days" value="90" min="1">
                <span class="summary-text">day(s) of logs.</span>
                <button type="submit" class="btn secondary" name="cleanup_logs" value="1">Delete older logs</button>
            </div>
            <div class="summary-text">
                This only deletes rows from <code>sync_logs</code>. It does not affect QuickBooks or PCO data.
            </div>
        </form>
    </div>

    <div class="card">
        <div class="section-header">
            <div>
                <p class="section-title">Most recent sync runs</p>
                <p class="section-sub">Latest 200 entries.</p>
            </div>
        </div>

        <?php if (empty($logs)): ?>
            <p class="summary-text">No log entries found.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Sync Type</th>
                        <th>Started At (UTC)</th>
                        <th>Finished At (UTC)</th>
                        <th>Status</th>
                        <th>Summary</th>
                        <th>Details</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($logs as $log): ?>
                        <?php
                        $status = (string)($log['status'] ?? '');
                        $statusClass = 'warn';
                        if ($status === 'success') {
                            $statusClass = 'ok';
                        } elseif ($status === 'error' || $status === 'failed') {
                            $statusClass = 'error';
                        } elseif ($status === 'partial') {
                            $statusClass = 'warn';
                        }
                        ?>
                        <tr>
                            <td><?= (int)$log['id'] ?></td>
                            <td class="run-type"><?= htmlspecialchars($log['sync_type'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($log['started_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($log['finished_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><span class="pill <?= $statusClass ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td class="summary-text"><?= htmlspecialchars($log['summary'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="details"><?= htmlspecialchars($log['details'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <div class="footer">
        &copy; <?= date('Y') ?> Rev. Tommy Sheppard • <a href="help.php">Help</a>
    </div>
</div>
</body>
</html>
