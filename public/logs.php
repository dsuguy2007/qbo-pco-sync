<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

$config = require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Auth.php';

Auth::requireLogin();

try {
    $db  = Db::getInstance($config['db']);
    $pdo = $db->getConnection();

    $stmt = $pdo->query('
        SELECT id, started_at, finished_at, window_start, window_end,
               donations_count, deposits_count, status, message
          FROM sync_logs
         ORDER BY id DESC
         LIMIT 50
    ');
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Error loading logs</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sync Logs</title>
    <style>
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 2rem; }
        table { border-collapse: collapse; width: 100%; max-width: 100%; }
        th, td { border: 1px solid #ddd; padding: 0.4rem 0.6rem; font-size: 0.9rem; }
        th { background: #f7f7f7; text-align:left; }
        .status-success { color: #0a7f1f; font-weight: 600; }
        .status-error { color: #c00; font-weight: 600; }
        .status-partial { color: #d18b00; font-weight: 600; }
        .small { font-size: 0.8rem; color:#666; }
    </style>
</head>
<body>

<h1>Sync Logs</h1>

<p><a href="index.php">&larr; Back to dashboard</a></p>

<?php if (empty($logs)): ?>
    <p>No sync logs yet.</p>
<?php else: ?>
    <table>
        <thead>
        <tr>
            <th>ID</th>
            <th>Started</th>
            <th>Finished</th>
            <th>Window Start</th>
            <th>Window End</th>
            <th>Donations</th>
            <th>Deposits</th>
            <th>Status</th>
            <th>Message</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($logs as $row): ?>
            <tr>
                <td><?= (int)$row['id'] ?></td>
                <td class="small"><?= htmlspecialchars((string)$row['started_at'], ENT_QUOTES, 'UTF-8') ?></td>
                <td class="small"><?= htmlspecialchars((string)$row['finished_at'], ENT_QUOTES, 'UTF-8') ?></td>
                <td class="small"><?= htmlspecialchars((string)$row['window_start'], ENT_QUOTES, 'UTF-8') ?></td>
                <td class="small"><?= htmlspecialchars((string)$row['window_end'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= (int)$row['donations_count'] ?></td>
                <td><?= (int)$row['deposits_count'] ?></td>
                <?php
                $status = $row['status'] ?? 'success';
                $statusClass = 'status-' . $status;
                ?>
                <td class="<?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td class="small">
                    <?= htmlspecialchars((string)$row['message'], ENT_QUOTES, 'UTF-8') ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

</body>
</html>
