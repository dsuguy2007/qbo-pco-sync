<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../src/Auth.php';
Auth::requireLogin();

function tail_log(string $path, int $lines = 50): array
{
    if (!file_exists($path)) {
        return ["(log not found: {$path})"];
    }
    $data = @file($path, FILE_IGNORE_NEW_LINES);
    if ($data === false) {
        return ["(unable to read {$path})"];
    }
    return array_slice($data, -1 * $lines);
}

$retryLog    = tail_log(__DIR__ . '/../logs/api-retries.log', 100);
$webhookLog  = tail_log(__DIR__ . '/../logs/webhooks.log', 100);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Diagnostics - Logs</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #0b1224; color: #e9eef7; margin: 0; }
        .page { max-width: 1100px; margin: 0 auto; padding: 2rem 1.25rem 3rem; }
        .card { background: rgba(22,32,55,0.9); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 1rem 1.1rem; margin-bottom: 1rem; }
        h1 { margin: 0 0 0.6rem; }
        h2 { margin: 0 0 0.4rem; font-size: 1.1rem; }
        pre { background: rgba(0,0,0,0.35); padding: 0.75rem; border-radius: 8px; overflow: auto; font-size: 0.9rem; color: #d7e2ff; }
        a { color: #2ea8ff; text-decoration: none; }
    </style>
</head>
<body>
<div class="page">
    <h1>Diagnostics: Logs</h1>
    <p><a href="index.php">&larr; Back to dashboard</a></p>

    <div class="card">
        <h2>API retries (last 100 lines)</h2>
        <pre><?= htmlspecialchars(implode("\n", $retryLog), ENT_QUOTES, 'UTF-8') ?></pre>
    </div>

    <div class="card">
        <h2>Webhook triggers (last 100 lines)</h2>
        <pre><?= htmlspecialchars(implode("\n", $webhookLog), ENT_QUOTES, 'UTF-8') ?></pre>
    </div>
</div>
</body>
</html>
