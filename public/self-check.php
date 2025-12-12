<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/PcoClient.php';
require_once __DIR__ . '/../src/QboClient.php';
require_once __DIR__ . '/../src/Auth.php';

Auth::requireLogin();

$results = [];

// DB + QBO
try {
    $db  = Db::getInstance($config['db']);
    $pdo = $db->getConnection();
    $results[] = ['label' => 'Database', 'status' => 'ok', 'detail' => 'Connected'];
} catch (Throwable $e) {
    $results[] = ['label' => 'Database', 'status' => 'error', 'detail' => $e->getMessage()];
}

$qbo = null;
if (isset($pdo)) {
    try {
        $qbo = new QboClient($pdo, $config);
        $results[] = ['label' => 'QuickBooks', 'status' => 'ok', 'detail' => 'Token loaded'];
    } catch (Throwable $e) {
        $results[] = ['label' => 'QuickBooks', 'status' => 'error', 'detail' => $e->getMessage()];
    }
}

// PCO basic connectivity + version header
$pcoVersion = null;
try {
    $pco = new PcoClient($config);
    $orgResp = $pco->listRegistrationPayments(['per_page' => 1]); // lightweight
    $results[] = ['label' => 'PCO Registrations', 'status' => 'ok', 'detail' => 'Connectivity OK'];
} catch (Throwable $e) {
    $results[] = ['label' => 'PCO Registrations', 'status' => 'error', 'detail' => $e->getMessage()];
}

// Payment methods required in QBO
if ($qbo) {
    $pmNames = ['Credit Card', 'ACH', 'EFT', 'cash', 'check'];
    foreach ($pmNames as $pm) {
        try {
            $pmObj = $qbo->getPaymentMethodByName($pm);
            if ($pmObj) {
                $results[] = ['label' => "Payment Method: {$pm}", 'status' => 'ok', 'detail' => 'Found'];
            } else {
                $results[] = ['label' => "Payment Method: {$pm}", 'status' => 'error', 'detail' => 'Not found in QBO'];
            }
        } catch (Throwable $e) {
            $results[] = ['label' => "Payment Method: {$pm}", 'status' => 'error', 'detail' => $e->getMessage()];
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Self Check</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; margin: 0; background: #0b1224; color: #e9eef7; }
        .page { max-width: 800px; margin: 0 auto; padding: 2rem 1.25rem; }
        .card { background: rgba(22,32,55,0.9); border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; padding: 1.1rem 1.2rem; box-shadow: 0 6px 20px rgba(0,0,0,0.25); }
        h1 { margin: 0 0 0.6rem; }
        ul { list-style: none; padding: 0; margin: 0; }
        li { padding: 0.6rem 0; border-bottom: 1px solid rgba(255,255,255,0.08); }
        li:last-child { border-bottom: none; }
        .status { display: inline-block; padding: 0.15rem 0.55rem; border-radius: 8px; font-size: 0.85rem; margin-right: 0.5rem; }
        .ok { background: rgba(57,217,138,0.15); color: #8ff0c2; border: 1px solid rgba(57,217,138,0.4); }
        .error { background: rgba(255,122,122,0.15); color: #ffadad; border: 1px solid rgba(255,122,122,0.4); }
        .muted { color: #9daccc; }
        a { color: #2ea8ff; text-decoration: none; }
    </style>
</head>
<body>
<div class="page">
    <h1>System self-check</h1>
    <div class="card">
        <ul>
            <?php foreach ($results as $row): ?>
                <li>
                    <span class="status <?= $row['status'] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8') ?></span>
                    <strong><?= htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8') ?></strong>
                    <div class="muted"><?= htmlspecialchars($row['detail'], ENT_QUOTES, 'UTF-8') ?></div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <p><a href="index.php">&larr; Back to dashboard</a></p>
</div>
</body>
</html>
