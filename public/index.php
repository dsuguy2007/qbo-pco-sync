<?php
declare(strict_types=1);


$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/PcoClient.php';
require_once __DIR__ . '/../src/Auth.php';
Auth::requireLogin();
// ---------------------------------------------------------------
// DB connection
// ---------------------------------------------------------------
try {
    $db  = Db::getInstance($config['db']);
    $pdo = $db->getConnection();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>QuickBooks / Planning Center Sync</h1>';
    echo '<div style="padding:0.6rem 0.8rem;background:#ffecec;border:1px solid #ffaeae;">';
    echo 'Database error:<br><pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre></div>';
    exit;
}

// ---------------------------------------------------------------
// Flash messages from OAuth callback (QBO connect)
// ---------------------------------------------------------------
$flashSuccess = false;
$flashError   = null;

// support either ?qbo_connected=1 or ?connected=1
if (isset($_GET['qbo_connected']) && $_GET['qbo_connected'] === '1') {
    $flashSuccess = true;
}
if (isset($_GET['connected']) && $_GET['connected'] === '1') {
    $flashSuccess = true;
}
if (!empty($_GET['qbo_error'])) {
    $flashError = (string)$_GET['qbo_error'];
}

// ---------------------------------------------------------------
// QBO connection status: look at qbo_tokens table
// ---------------------------------------------------------------
$qboConnected = false;
$qboStatusText = 'Not yet connected to QuickBooks.';

try {
    $stmt = $pdo->query("SELECT * FROM qbo_tokens ORDER BY id DESC LIMIT 1");
    $tokenRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($tokenRow) {
        $realmId   = $tokenRow['realm_id'] ?? '';
        $expiresAt = null;

        if (!empty($tokenRow['expires_at'])) {
            try {
                $expiresAt = new DateTimeImmutable($tokenRow['expires_at']);
            } catch (Throwable $e) {
                $expiresAt = null;
            }
        }

        $now = new DateTimeImmutable('now');

        if ($expiresAt && $expiresAt > $now) {
            $qboConnected  = true;
            $qboStatusText = 'Connected to QuickBooks (realm ' . htmlspecialchars($realmId, ENT_QUOTES, 'UTF-8') . ').';
        } else {
            // We have a row but token is expired or can’t parse date
            $qboStatusText = 'QuickBooks token has expired or is invalid. Please reconnect.';
        }
    }
} catch (Throwable $e) {
    $qboStatusText = 'Error checking QuickBooks status: ' . $e->getMessage();
}

// ---------------------------------------------------------------
// PCO status: simple check using config
// ---------------------------------------------------------------
$pcoConnected  = false;
$pcoStatusText = 'Not configured.';

try {
    if (!empty($config['pco']['app_id']) && !empty($config['pco']['secret'])) {
        // Just constructing the client is enough to know config is present.
        $pcoClient     = new PcoClient($config);
        $pcoConnected  = true;
        $pcoStatusText = 'Configured (credentials present).';
    } else {
        $pcoStatusText = 'Missing PCO app_id or secret in config.';
    }
} catch (Throwable $e) {
    $pcoStatusText = 'Error initializing PCO client: ' . $e->getMessage();
}

// ---------------------------------------------------------------
// Optional: notification email from sync_settings (if present)
// ---------------------------------------------------------------
$notificationEmail = null;
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM sync_settings WHERE setting_key = 'notification_email' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['setting_value'])) {
        $notificationEmail = $row['setting_value'];
    }
} catch (Throwable $e) {
    // Don’t break the page if this fails; just omit the email display.
    $notificationEmail = null;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>QuickBooks / Planning Center Sync</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 2rem;
        }
        h1 {
            margin-bottom: 1rem;
        }
        .flash-success {
            padding: 0.6rem 0.8rem;
            background: #e6ffed;
            border: 1px solid #b7eb8f;
            margin-bottom: 1rem;
        }
        .flash-error {
            padding: 0.6rem 0.8rem;
            background: #ffecec;
            border: 1px solid #ffaeae;
            margin-bottom: 1rem;
        }
        .status-box {
            padding: 0.6rem 0.8rem;
            border-radius: 4px;
            margin-bottom: 0.75rem;
        }
        .status-ok {
            background: #f6ffed;
            border: 1px solid #b7eb8f;
        }
        .status-warn {
            background: #fff7e6;
            border: 1px solid #ffd591;
        }
        .status-error {
            background: #ffecec;
            border: 1px solid #ffaeae;
        }
        .btn {
            display: inline-block;
            padding: 0.5rem 0.9rem;
            background: #1677ff;
            color: #fff;
            text-decoration: none;
            border-radius: 4px;
            margin-bottom: 1rem;
        }
        .btn:hover {
            background: #0b5ed7;
        }
        hr {
            margin: 1.5rem 0;
        }
        ul {
            margin-top: 0.4rem;
        }
        .small {
            font-size: 0.85rem;
            color: #666;
        }
        a {
            color: #1677ff;
        }
    </style>
</head>
<body>

<h1>QuickBooks / Planning Center Sync</h1>

<?php if ($flashSuccess): ?>
    <div class="flash-success">
        Successfully connected to QuickBooks and stored tokens.
    </div>
<?php endif; ?>

<?php if ($flashError): ?>
    <div class="flash-error">
        Error during QuickBooks connection:<br>
        <?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<div class="status-box <?= $qboConnected ? 'status-ok' : 'status-warn' ?>">
    <strong>QuickBooks status:</strong>
    <?= $qboStatusText ?>
</div>

<div class="status-box <?= $pcoConnected ? 'status-ok' : 'status-warn' ?>">
    <strong>Planning Center status:</strong>
    <?= htmlspecialchars($pcoStatusText, ENT_QUOTES, 'UTF-8') ?>
</div>

<?php if ($notificationEmail): ?>
    <p class="small">
        Notifications will be sent to:
        <strong><?= htmlspecialchars($notificationEmail, ENT_QUOTES, 'UTF-8') ?></strong>
    </p>
<?php endif; ?>

<p>
    <a href="oauth-start.php" class="btn">
        <?= $qboConnected ? 'Reconnect QuickBooks' : 'Connect to QuickBooks' ?>
    </a>
</p>

<hr>
<p><a href="settings.php">Settings (accounts & notification email)</a></p>
<p><a href="fund-mapping.php">Manage Fund &rarr; QBO Class/Location mappings</a></p>
<p><a href="run-sync-preview.php">Preview Stripe donations (completed_at filter)</a></p>
<p><a href="run-sync.php">Run sync to QuickBooks now</a></p>
<p><a href="run-batch-sync.php">Run committed batch sync now</a></p>

<p></p>
<p><a href="logout.php">Log out</a></p>
<p><a href="logs.php">View recent sync logs</a></p>


<p class="small" style="margin-top:1.5rem;">
    This dashboard will eventually let you:
</p>
<ul class="small">
    <li>Trigger a manual sync of PCO Stripe payouts &rarr; QBO deposits</li>
    <li>View recent sync logs and errors</li>
    <li>Adjust fund &rarr; account/class/location mappings</li>
    <li>Set the notification email address</li>
</ul>

</body>
</html>
