<?php
declare(strict_types=1);


// Load config and common classes
$config = require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/PcoClient.php';
require_once __DIR__ . '/../src/Auth.php';
Auth::requireLogin();
// ---- DB connection via Db helper ----
try {
    $db  = Db::getInstance($config['db']);
    $pdo = $db->getConnection();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Database error</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

// ---- PCO client ----
try {
    $pcoClient = new PcoClient($config);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>PCO client error</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

$flashMessage = null;
$errorMessage = null;

// ---- Handle POST (save mappings) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_POST['fund']) || !is_array($_POST['fund'])) {
            throw new RuntimeException('No fund data submitted.');
        }

        $stmt = $pdo->prepare(
            "INSERT INTO fund_mappings (pco_fund_id, pco_fund_name, qbo_class_name, qbo_location_name)
             VALUES (:pco_fund_id, :pco_fund_name, :qbo_class_name, :qbo_location_name)
             ON DUPLICATE KEY UPDATE
               pco_fund_name = VALUES(pco_fund_name),
               qbo_class_name = VALUES(qbo_class_name),
               qbo_location_name = VALUES(qbo_location_name),
               updated_at = CURRENT_TIMESTAMP"
        );

        foreach ($_POST['fund'] as $fundId => $row) {
            $pcoFundId   = (string)($row['pco_fund_id'] ?? '');
            $pcoFundName = trim((string)($row['pco_fund_name'] ?? ''));
            $className   = trim((string)($row['qbo_class_name'] ?? ''));
            $locName     = trim((string)($row['qbo_location_name'] ?? ''));

            if ($pcoFundId === '' || $pcoFundName === '') {
                continue;
            }

            $stmt->execute([
                ':pco_fund_id'       => $pcoFundId,
                ':pco_fund_name'     => $pcoFundName,
                ':qbo_class_name'    => $className !== '' ? $className : null,
                ':qbo_location_name' => $locName !== '' ? $locName : null,
            ]);
        }

        $flashMessage = 'Mappings saved.';
    } catch (Throwable $e) {
        $errorMessage = 'Error saving mappings: ' . $e->getMessage();
    }
}

// ---- Load current PCO funds ----
try {
    $funds = $pcoClient->listFunds();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Error loading PCO funds</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

// ---- Load existing mappings from DB ----
$existingMappings = [];
try {
    $rows = $pdo->query("SELECT * FROM fund_mappings")->fetchAll();
    foreach ($rows as $row) {
        $existingMappings[$row['pco_fund_id']] = $row;
    }
} catch (Throwable $e) {
    $errorMessage = 'Error loading existing mappings: ' . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Fund → QBO Class/Location Mapping</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 2rem;
        }
        table {
            border-collapse: collapse;
            width: 100%;
            max-width: 1100px;
        }
        th, td {
            border: 1px solid #ccc;
            padding: 0.4rem 0.6rem;
            vertical-align: top;
            font-size: 0.9rem;
        }
        th {
            background: #f5f5f5;
            text-align: left;
        }
        input[type="text"] {
            width: 100%;
            box-sizing: border-box;
        }
        .flash {
            padding: 0.6rem 0.8rem;
            margin-bottom: 1rem;
            background: #e6ffed;
            border: 1px solid #b7eb8f;
        }
        .error {
            padding: 0.6rem 0.8rem;
            margin-bottom: 1rem;
            background: #ffecec;
            border: 1px solid #ffaeae;
        }
        .top-nav a {
            display: inline-block;
            margin-bottom: 1rem;
        }
        .small {
            font-size: 0.8rem;
            color: #666;
        }
        .sticky-header {
            position: sticky;
            top: 0;
            z-index: 2;
        }
    </style>
</head>
<body>

<div class="top-nav">
    <a href="index.php">&larr; Back to main dashboard</a>
</div>

<h1>Fund → QBO Class / Location Mapping</h1>
<p class="small">
    For each Planning Center <strong>Fund</strong>, enter the QuickBooks <strong>Class</strong> and <strong>Location</strong> (Department)
    names exactly as they appear in QBO.<br>
    We’ll use these when creating deposits.
</p>

<?php if ($flashMessage): ?>
    <div class="flash"><?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if ($errorMessage): ?>
    <div class="error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<form method="post">
    <table>
        <thead class="sticky-header">
        <tr>
            <th>PCO Fund ID</th>
            <th>PCO Fund Name</th>
            <th>QBO Class Name<br><span class="small">e.g. <em>Trinity – General</em></span></th>
            <th>QBO Location Name<br><span class="small">e.g. <em>Moundsville</em></span></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($funds as $fund): ?>
            <?php
            $fid   = isset($fund['id']) ? (string)$fund['id'] : '';
            $attrs = $fund['attributes'] ?? [];
            $fname = isset($attrs['name']) ? (string)$attrs['name'] : '';

            $map   = $existingMappings[$fid] ?? null;
            ?>
            <tr>
                <td>
                    <?= htmlspecialchars($fid, ENT_QUOTES, 'UTF-8') ?>
                    <input type="hidden"
                           name="fund[<?= htmlspecialchars($fid, ENT_QUOTES, 'UTF-8') ?>][pco_fund_id]"
                           value="<?= htmlspecialchars($fid, ENT_QUOTES, 'UTF-8') ?>">
                </td>
                <td>
                    <?= htmlspecialchars($fname, ENT_QUOTES, 'UTF-8') ?>
                    <input type="hidden"
                           name="fund[<?= htmlspecialchars($fid, ENT_QUOTES, 'UTF-8') ?>][pco_fund_name]"
                           value="<?= htmlspecialchars($fname, ENT_QUOTES, 'UTF-8') ?>">
                </td>
                <td>
                    <input type="text"
                           name="fund[<?= htmlspecialchars($fid, ENT_QUOTES, 'UTF-8') ?>][qbo_class_name]"
                           value="<?= htmlspecialchars($map['qbo_class_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </td>
                <td>
                    <input type="text"
                           name="fund[<?= htmlspecialchars($fid, ENT_QUOTES, 'UTF-8') ?>][qbo_location_name]"
                           value="<?= htmlspecialchars($map['qbo_location_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <p style="margin-top: 1rem;">
        <button type="submit">Save mappings</button>
    </p>
</form>

</body>
</html>
