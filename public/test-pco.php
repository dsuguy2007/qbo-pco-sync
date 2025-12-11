<?php
declare(strict_types=1);


// public/test-pco.php

$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../src/PcoClient.php';
require_once __DIR__ . '/../src/Auth.php';
Auth::requireLogin();
$error = null;
$funds = [];

try {
    $pco   = new PcoClient($config);
    $funds = $pco->listFunds();
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PCO Funds Test</title>
    <style>
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 2rem; }
        table { border-collapse: collapse; width: 100%; max-width: 900px; }
        th, td { border: 1px solid #ccc; padding: 0.4rem 0.6rem; text-align: left; }
        th { background: #f3f3f3; }
        .error { color: #b00020; font-weight: bold; margin-bottom: 1rem; }
        .tag { display: inline-block; background: #eef; border-radius: 999px; padding: 0.1rem 0.5rem; font-size: 0.8rem; }
        .muted { color: #666; font-size: 0.85rem; }
    </style>
</head>
<body>
    <h1>Planning Center Giving &mdash; Funds Test</h1>

    <?php if ($error): ?>
        <div class="error">
            Error talking to PCO:<br>
            <code><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></code>
        </div>
        <p class="muted">
            Double-check:
            <ul>
                <li>That your <code>pco.app_id</code> and <code>pco.secret</code> are correct in <code>config.php</code>.</li>
                <li>That your Personal Access Token has access to the Giving API.</li>
            </ul>
        </p>
    <?php else: ?>
        <p>PCO connection looks good. Here are the funds I can see in Planning Center Giving:</p>

        <?php if (empty($funds)): ?>
            <p><em>No funds returned from PCO.</em></p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Fund ID</th>
                        <th>Name</th>
                        <th>Ledger Code</th>
                        <th>Visibility</th>
                        <th>Default?</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($funds as $fund): ?>
                    <?php
                        $id   = $fund['id'] ?? '';
                        $attr = $fund['attributes'] ?? [];
                    ?>
                    <tr>
                        <td><code><?= htmlspecialchars((string)$id, ENT_QUOTES, 'UTF-8') ?></code></td>
                        <td><?= htmlspecialchars((string)($attr['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($attr['ledger_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?php if (!empty($attr['visibility'])): ?>
                                <span class="tag"><?= htmlspecialchars((string)$attr['visibility'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= !empty($attr['default']) ? 'Yes' : 'No' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>

    <p class="muted" style="margin-top: 1.5rem;">
        Once this page is working, we’ll use these Fund IDs to map to your QBO Classes & Locations for automatic deposits.
    </p>
</body>
</html>
