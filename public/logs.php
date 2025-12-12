<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Auth.php';

Auth::requireLogin();

$db  = Db::getInstance($config['db']);
$pdo = $db->getPdo();

$message = null;
$error   = null;

// Handle cleanup POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cleanup_logs'])) {
    try {
        $days = isset($_POST['retention_days']) ? (int)$_POST['retention_days'] : 90;
        if ($days < 1) {
            $days = 1;
        }

        $nowUtc  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $cutoff  = $nowUtc->modify("-{$days} days")->format('Y-m-d H:i:s');

        $stmt = $pdo->prepare('DELETE FROM sync_logs WHERE finished_at IS NOT NULL AND finished_at < :cutoff');
        $stmt->execute([':cutoff' => $cutoff]);
        $deleted = $stmt->rowCount();

        $message = "Deleted {$deleted} log entr" . ($deleted === 1 ? 'y' : 'ies') . " older than {$days} day" . ($days === 1 ? '' : 's') . '.';
    } catch (Throwable $e) {
        $error = 'Error cleaning up logs: ' . $e->getMessage();
    }
}

// Fetch latest logs for display
$stmt = $pdo->query('
    SELECT id, run_type, started_at, finished_at, status, summary, details
    FROM sync_logs
    ORDER BY started_at DESC
    LIMIT 200
');
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sync Logs - PCO → QBO Sync</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 20px;
        }
        h1 {
            margin-bottom: 0.25rem;
        }
        .nav {
            margin-bottom: 1rem;
        }
        .nav a {
            margin-right: 1rem;
        }
        .flash {
            padding: 0.5rem 0.75rem;
            margin-bottom: 1rem;
            border-radius: 4px;
        }
        .flash-success {
            background-color: #e6ffed;
            border: 1px solid #34c759;
            color: #03420f;
        }
        .flash-error {
            background-color: #ffecec;
            border: 1px solid #ff3b30;
            color: #5f1111;
        }
        form.cleanup-form {
            margin-bottom: 1rem;
            padding: 0.75rem;
            border-radius: 4px;
            border: 1px solid #ddd;
            max-width: 420px;
        }
        form.cleanup-form label {
            display: inline-block;
            margin-right: 0.5rem;
        }
        form.cleanup-form input[type="number"] {
            width: 80px;
        }
        form.cleanup-form button {
            margin-left: 0.5rem;
        }
        table {
            border-collapse: collapse;
            width: 100%;
            max-width: 100%;
            font-size: 0.9rem;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 0.35rem 0.5rem;
            vertical-align: top;
        }
        th {
            background-color: #f7f7f7;
            text-align: left;
        }
        .status-success {
            color: #0a7c28;
            font-weight: 600;
        }
        .status-error {
            color: #b00020;
            font-weight: 600;
        }
        .status-partial {
            color: #c27800;
            font-weight: 600;
        }
        .details {
            white-space: pre-wrap;
            max-width: 500px;
        }
        .run-type {
            text-transform: capitalize;
        }
    </style>
</head>
<body>
    <h1>Sync Logs</h1>
    <div class="nav">
        <a href="index.php">&laquo; Back to Dashboard</a>
    </div>

    <?php if ($message): ?>
        <div class="flash flash-success">
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="flash flash-error">
            <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <form method="post" class="cleanup-form">
        <strong>Log cleanup</strong><br>
        <label for="retention_days">Keep the last</label>
        <input type="number" id="retention_days" name="retention_days" value="90" min="1">
        <span>day(s) of logs.</span>
        <button type="submit" name="cleanup_logs" value="1">Delete older logs</button>
        <div style="margin-top: 0.25rem; font-size: 0.8rem; color: #555;">
            This only deletes old rows from the <code>sync_logs</code> table. It does not affect QuickBooks or PCO data.
        </div>
    </form>

    <h2>Most Recent Sync Runs (latest 200)</h2>
    <?php if (empty($logs)): ?>
        <p>No log entries found.</p>
    <?php else: ?>
        <table>
            <thead>
            <tr>
                <th>ID</th>
                <th>Run Type</th>
                <th>Started At (UTC)</th>
                <th>Finished At (UTC)</th>
                <th>Status</th>
                <th>Summary</th>
                <th>Details</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?php echo (int)$log['id']; ?></td>
                    <td class="run-type">
                        <?php echo htmlspecialchars($log['run_type'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                    </td>
                    <td><?php echo htmlspecialchars($log['started_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($log['finished_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="<?php
                        $status = $log['status'] ?? '';
                        echo 'status-' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8');
                    ?>">
                        <?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>
                    </td>
                    <td>
                        <?php echo htmlspecialchars($log['summary'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                    </td>
                    <td class="details">
                        <?php echo htmlspecialchars($log['details'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</body>
</html>
