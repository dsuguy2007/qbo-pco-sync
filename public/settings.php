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
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Database error</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

function get_setting(PDO $pdo, string $key): ?string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM sync_settings WHERE setting_key = :key ORDER BY id DESC LIMIT 1');
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && isset($row['setting_value'])) {
        return (string)$row['setting_value'];
    }
    return null;
}

function set_setting(PDO $pdo, string $key, string $value): void
{
    // Ensure only one row per key
    $stmt = $pdo->prepare('DELETE FROM sync_settings WHERE setting_key = :key');
    $stmt->execute([':key' => $key]);

    $stmt = $pdo->prepare('INSERT INTO sync_settings (setting_key, setting_value) VALUES (:key, :value)');
    $stmt->execute([
        ':key'   => $key,
        ':value' => $value,
    ]);
}

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $notificationEmail      = trim($_POST['notification_email'] ?? '');
    $depositBankAccountName = trim($_POST['deposit_bank_account_name'] ?? '');
    $incomeAccountName      = trim($_POST['income_account_name'] ?? '');
    $stripeFeeAccountName   = trim($_POST['stripe_fee_account_name'] ?? '');
    $enableBatchSync        = isset($_POST['enable_batch_sync']) ? '1' : '0';

    // Basic validation
    if ($notificationEmail !== '' && !filter_var($notificationEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Notification email is not a valid email address.';
    }

    if ($depositBankAccountName === '') {
        $errors[] = 'Deposit bank account name is required.';
    }

    if ($incomeAccountName === '') {
        $errors[] = 'Income account name is required.';
    }

    if ($stripeFeeAccountName === '') {
        $errors[] = 'Stripe fee expense account name is required.';
    }

    if (empty($errors)) {
        try {
            set_setting($pdo, 'notification_email', $notificationEmail);
            set_setting($pdo, 'deposit_bank_account_name', $depositBankAccountName);
            set_setting($pdo, 'income_account_name', $incomeAccountName);
            set_setting($pdo, 'stripe_fee_account_name', $stripeFeeAccountName);
            set_setting($pdo, 'enable_batch_sync', $enableBatchSync);

            $success = true;
        } catch (Throwable $e) {
            $errors[] = 'Error saving settings: ' . $e->getMessage();
        }
    }
}

// Load current values (with sensible defaults)
$notificationEmail      = get_setting($pdo, 'notification_email') ?? '';
$depositBankAccountName = get_setting($pdo, 'deposit_bank_account_name') ?? 'TRINITY 2000 CHECKING';
$incomeAccountName      = get_setting($pdo, 'income_account_name') ?? 'OPERATING INCOME:WEEKLY OFFERINGS:PLEDGES';
$stripeFeeAccountName   = get_setting($pdo, 'stripe_fee_account_name') ?? 'OPERATING EXPENSES:MINISTRY EXPENSES:PROCESSING FEES';
$enableBatchSync        = get_setting($pdo, 'enable_batch_sync') ?? '0';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sync Settings</title>
    <style>
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 2rem; }
        h1 { margin-bottom: 0.5rem; }
        .muted { font-size: 0.85rem; color: #666; }
        .box { max-width: 700px; }
        label { display: block; margin-top: 1rem; font-weight: 600; }
        input[type="text"], input[type="email"] {
            width: 100%;
            max-width: 500px;
            padding: 0.4rem 0.5rem;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .checkbox-row { margin-top: 1rem; }
        .checkbox-row label { display: inline-block; font-weight: 400; margin-left: 0.3rem; }
        button {
            margin-top: 1.5rem;
            padding: 0.4rem 0.9rem;
            border-radius: 4px;
            border: none;
            background: #1677ff;
            color: #fff;
            cursor: pointer;
        }
        button:hover { background: #0b5ed7; }
        .ok { padding: 0.6rem 0.8rem; background: #e6ffed; border: 1px solid #b7eb8f; margin-bottom: 1rem; }
        .error { padding: 0.6rem 0.8rem; background: #ffecec; border: 1px solid #ffaeae; margin-bottom: 1rem; }
        ul { margin: 0.4rem 0 0 1.2rem; }
        a { color: #1677ff; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="box">
    <h1>Sync Settings</h1>
    <p class="muted"><a href="index.php">&larr; Back to dashboard</a></p>

    <?php if ($success): ?>
        <div class="ok">Settings saved.</div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="error">
            <strong>There were problems saving your settings:</strong>
            <ul>
                <?php foreach ($errors as $err): ?>
                    <li><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="settings.php">
        <h2>QuickBooks Accounts</h2>

        <label for="deposit_bank_account_name">Deposit bank account name (QBO)</label>
        <input
            id="deposit_bank_account_name"
            name="deposit_bank_account_name"
            type="text"
            value="<?= htmlspecialchars($depositBankAccountName, ENT_QUOTES, 'UTF-8') ?>"
            required
        >
        <p class="muted">
            This should match the <strong>Bank</strong> account name in QuickBooks where deposits are recorded
            (e.g. <code>TRINITY 2000 CHECKING</code>).
        </p>

        <label for="income_account_name">Income account name (QBO)</label>
        <input
            id="income_account_name"
            name="income_account_name"
            type="text"
            value="<?= htmlspecialchars($incomeAccountName, ENT_QUOTES, 'UTF-8') ?>"
            required
        >
        <p class="muted">
            Used for the gross donation lines (e.g. <code>OPERATING INCOME:WEEKLY OFFERINGS:PLEDGES</code>).
        </p>

        <label for="stripe_fee_account_name">Stripe fee expense account name (QBO)</label>
        <input
            id="stripe_fee_account_name"
            name="stripe_fee_account_name"
            type="text"
            value="<?= htmlspecialchars($stripeFeeAccountName, ENT_QUOTES, 'UTF-8') ?>"
            required
        >
        <p class="muted">
            Used for the negative Stripe fee lines (e.g. <code>OPERATING EXPENSES:MINISTRY EXPENSES:PROCESSING FEES</code>).
        </p>

        <h2>Notifications</h2>

        <label for="notification_email">Notification email</label>
        <input
            id="notification_email"
            name="notification_email"
            type="email"
            value="<?= htmlspecialchars($notificationEmail, ENT_QUOTES, 'UTF-8') ?>"
            placeholder="you@example.org"
        >
        <p class="muted">
            If set, the app will send an email when a sync run ends with errors.
        </p>

        <h2>Batch Sync (Checks & Cash)</h2>

        <div class="checkbox-row">
            <input
                id="enable_batch_sync"
                name="enable_batch_sync"
                type="checkbox"
                value="1"
                <?= $enableBatchSync === '1' ? 'checked' : '' ?>
            >
            <label for="enable_batch_sync">
                Enable syncing committed PCO batches (cash & checks) to QuickBooks
            </label>
        </div>
        <p class="muted">
            When enabled, the app will (in a later step) be able to sync <strong>committed batches</strong>
            from PCO Giving into QBO Deposits.  
            Each batch will be turned into one or more QBO Deposit records, with the
            <strong>Batch ID</strong> included in the memo.
        </p>

        <button type="submit">Save settings</button>
    </form>
</div>
</body>
</html>
