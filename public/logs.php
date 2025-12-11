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
    echo 'DB error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
}

// Get last 50 logs
$stmt = $pdo->query(
    'SELECT id, sync_type, started_at, finished_at, status, summary, created_at
       FROM sync_logs
   ORDER BY id DESC
      LIMIT 50'
);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sync Logs</title>
    <style>
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 2rem; }
        table { border-collapse: collapse; width: 100%; max-width: 100%; }
        th, td { border: 1px solid #ddd; padding: 0.35rem 0.5rem; font-size: 0.9rem; }
        th { background: #f7f7f7; }
        .status-success { color: #207227; font-weight: 600; }
        .status-error { color: #b00020; font-weight: 600; }
        .status-partial { color: #b36b00; font-weight: 600; }
        .muted { color: #666; font-size: 0.85rem; }
    </style>
</head>
<body>
<h1>Sync Logs</h1>

<p class="muted">Showing the 50 most recent sync runs (Stripe + Batches).</p>

<table>
    <thead>
    <tr>
        <th>ID</th>
        <th>Type</th>
        <th>Started</th>
        <th>Finished</th>
        <th>Status</th>
        <th>Summary</th>
    </tr>
    </thead>
    <tbody>
    <?php if (empty($logs)): ?>
        <tr><td colspan="6" class="muted">No sync logs yet.</td></tr>
    <?php else: ?>
        <?php foreach ($logs as $log): ?>
            <tr>
                <td><?= (int)$log['id'] ?></td>
                <td><?= htmlspecialchars($log['sync_type'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($log['started_at'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($log['finished_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td class="status-<?= htmlspecialchars($log['status'], ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($log['status'], ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td><?= htmlspecialchars($log['summary'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>

<p style="margin-top:1.5rem;">
    <a href="index.php">&larr; Back to dashboard</a>
</p>

</body>
</html>
