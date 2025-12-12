<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Auth.php';

Auth::requireLogin();

// --- Helper functions --------------------------------------------------------

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
    // Ensure only one row per setting_key
    $stmt = $pdo->prepare('DELETE FROM sync_settings WHERE setting_key = :key');
    $stmt->execute([':key' => $key]);

    $stmt = $pdo->prepare('INSERT INTO sync_settings (setting_key, setting_value) VALUES (:key, :value)');
    $stmt->execute([
        ':key'   => $key,
        ':value' => $value,
    ]);
}

// --- Bootstrap DB ------------------------------------------------------------

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

// --- Handle form submission --------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Notifications
        $notificationEmail = isset($_POST['notification_email'])
            ? trim((string)$_POST['notification_email'])
            : '';
        if ($notificationEmail !== '') {
            set_setting($pdo, 'notification_email', $notificationEmail);
        } else {
            // allow blank to "turn off" notifications
            set_setting($pdo, 'notification_email', '');
        }

        // Stripe payout sync (online / Stripe donations)
        $depositBankName = trim((string)($_POST['deposit_bank_account_name'] ?? ''));
        $incomeName      = trim((string)($_POST['income_account_name'] ?? ''));
        $feeName         = trim((string)($_POST['stripe_fee_account_name'] ?? ''));

        if ($depositBankName !== '') {
            set_setting($pdo, 'deposit_bank_account_name', $depositBankName);
        }
        if ($incomeName !== '') {
            set_setting($pdo, 'income_account_name', $incomeName);
        }
        if ($feeName !== '') {
            set_setting($pdo, 'stripe_fee_account_name', $feeName);
        }

        // Batch sync (committed PCO batches)
        $enableBatchSync = isset($_POST['enable_batch_sync']) ? '1' : '0';
        set_setting($pdo, 'enable_batch_sync', $enableBatchSync);

        $batchBankName   = trim((string)($_POST['batch_deposit_bank_account_name'] ?? ''));
        $batchIncomeName = trim((string)($_POST['batch_income_account_name'] ?? ''));

        if ($batchBankName !== '') {
            set_setting($pdo, 'batch_deposit_bank_account_name', $batchBankName);
        }
        if ($batchIncomeName !== '') {
            set_setting($pdo, 'batch_income_account_name', $batchIncomeName);
        }

        $message = 'Settings saved successfully.';
    } catch (Throwable $e) {
        $error = 'Error saving settings: ' . $e->getMessage();
    }
}

// --- Load current values -----------------------------------------------------

$notificationEmail = get_setting($pdo, 'notification_email') ?? '';

// Stripe payout sync settings
$depositBankName = get_setting($pdo, 'deposit_bank_account_name')
    ?? 'TRINITY 2000 CHECKING';

$incomeAccountName = get_setting($pdo, 'income_account_name')
    ?? 'OPERATING INCOME:WEEKLY OFFERINGS:PLEDGES';

$stripeFeeAccountName = get_setting($pdo, 'stripe_fee_account_name')
    ?? 'OPERATING EXPENSES:MINISTRY EXPENSES:PROCESSING FEES';

// Batch sync settings (now also in sync_settings)
$enableBatchSync = (get_setting($pdo, 'enable_batch_sync') === '1');

$batchDepositBankName = get_setting($pdo, 'batch_deposit_bank_account_name')
    ?? $depositBankName;

$batchIncomeAccountName = get_setting($pdo, 'batch_income_account_name')
    ?? $incomeAccountName;

// Last sync windows (read-only info)
$lastStripeCompletedAt = get_setting($pdo, 'last_completed_at');
$lastBatchCompletedAt  = get_setting($pdo, 'last_batch_sync_completed_at');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Settings - PCO → QBO Sync</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 20px;
            line-height: 1.4;
        }
        h1 {
            margin-bottom: 0.25rem;
        }
        h2 {
            margin-top: 1.5rem;
            margin-bottom: 0.5rem;
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
        form {
            max-width: 720px;
        }
        fieldset {
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 0.75rem 1rem 1rem;
            margin-bottom: 1rem;
        }
        legend {
            padding: 0 0.5rem;
            font-weight: 600;
        }
        .field {
            margin-bottom: 0.75rem;
        }
        .field label {
            display: block;
            font-weight: 500;
            margin-bottom: 0.25rem;
        }
        .field input[type="text"],
        .field input[type="email"] {
            width: 100%;
            max-width: 420px;
            padding: 0.35rem 0.45rem;
            border-radius: 4px;
            border: 1px solid #ccc;
            font-size: 0.95rem;
        }
        .hint {
            font-size: 0.8rem;
            color: #555;
            margin-top: 0.2rem;
        }
        .checkbox-row {
            margin-bottom: 0.75rem;
        }
        .checkbox-row label {
            font-weight: 500;
        }
        .readonly-values {
            font-size: 0.9rem;
            color: #333;
        }
        .readonly-values code {
            font-size: 0.85rem;
        }
        button[type="submit"] {
            padding: 0.4rem 0.9rem;
            border-radius: 4px;
            border: 1px solid #0a7c28;
            background-color: #0a7c28;
            color: white;
            cursor: pointer;
            font-size: 0.95rem;
        }
        button[type="submit"]:hover {
            background-color: #096322;
        }
    </style>
</head>
<body>
    <h1>Settings</h1>
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

    <form method="post">

        <fieldset>
            <legend>Notifications</legend>
            <div class="field">
                <label for="notification_email">Error / warning notification email</label>
                <input
                    type="email"
                    id="notification_email"
                    name="notification_email"
                    value="<?php echo htmlspecialchars($notificationEmail, ENT_QUOTES, 'UTF-8'); ?>"
                >
                <div class="hint">
                    If set, the app will send an email when a Stripe or batch sync finishes with errors or a partial success.
                    Leave blank to disable email notifications.
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend>Stripe payout sync (online donations)</legend>

            <div class="field">
                <label for="deposit_bank_account_name">Deposit bank account (QBO name)</label>
                <input
                    type="text"
                    id="deposit_bank_account_name"
                    name="deposit_bank_account_name"
                    value="<?php echo htmlspecialchars($depositBankName, ENT_QUOTES, 'UTF-8'); ?>"
                >
                <div class="hint">
                    This should match the name of the bank account in QuickBooks that receives Stripe deposits
                    (for example: <code>TRINITY 2000 CHECKING</code>).
                </div>
            </div>

            <div class="field">
                <label for="income_account_name">Income account (QBO name)</label>
                <input
                    type="text"
                    id="income_account_name"
                    name="income_account_name"
                    value="<?php echo htmlspecialchars($incomeAccountName, ENT_QUOTES, 'UTF-8'); ?>"
                >
                <div class="hint">
                    This is used for the gross donation lines (for example:
                    <code>OPERATING INCOME:WEEKLY OFFERINGS:PLEDGES</code>).
                </div>
            </div>

            <div class="field">
                <label for="stripe_fee_account_name">Stripe fee expense account (QBO name)</label>
                <input
                    type="text"
                    id="stripe_fee_account_name"
                    name="stripe_fee_account_name"
                    value="<?php echo htmlspecialchars($stripeFeeAccountName, ENT_QUOTES, 'UTF-8'); ?>"
                >
                <div class="hint">
                    This is used for the negative fee lines (for example:
                    <code>OPERATING EXPENSES:MINISTRY EXPENSES:PROCESSING FEES</code>).
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend>Batch sync (committed PCO Giving batches)</legend>

            <div class="checkbox-row">
                <label>
                    <input
                        type="checkbox"
                        name="enable_batch_sync"
                        value="1"
                        <?php echo $enableBatchSync ? 'checked' : ''; ?>
                    >
                    Enable sync of committed PCO Giving batches to QuickBooks
                </label>
                <div class="hint">
                    When enabled, the batch sync will create deposits in QBO for committed batches,
                    grouping by fund and applying the correct QBO Class and Location based on your fund mapping.
                </div>
            </div>

            <div class="field">
                <label for="batch_deposit_bank_account_name">Batch deposit bank account (QBO name)</label>
                <input
                    type="text"
                    id="batch_deposit_bank_account_name"
                    name="batch_deposit_bank_account_name"
                    value="<?php echo htmlspecialchars($batchDepositBankName, ENT_QUOTES, 'UTF-8'); ?>"
                >
                <div class="hint">
                    Bank account used for deposits created from committed batches.
                    Defaults to the Stripe deposit account if left the same.
                </div>
            </div>

            <div class="field">
                <label for="batch_income_account_name">Batch income account (QBO name)</label>
                <input
                    type="text"
                    id="batch_income_account_name"
                    name="batch_income_account_name"
                    value="<?php echo htmlspecialchars($batchIncomeAccountName, ENT_QUOTES, 'UTF-8'); ?>"
                >
                <div class="hint">
                    Income account used for gross donation lines from committed batches.
                    Defaults to the Stripe income account if left the same.
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend>Sync windows (read-only)</legend>
            <div class="readonly-values">
                <div>
                    <strong>Last Stripe payout sync window end (UTC):</strong>
                    <?php echo $lastStripeCompletedAt
                        ? htmlspecialchars($lastStripeCompletedAt, ENT_QUOTES, 'UTF-8')
                        : '<em>Not set yet</em>'; ?>
                </div>
                <div>
                    <strong>Last batch sync window end (UTC):</strong>
                    <?php echo $lastBatchCompletedAt
                        ? htmlspecialchars($lastBatchCompletedAt, ENT_QUOTES, 'UTF-8')
                        : '<em>Not set yet</em>'; ?>
                </div>
                <div class="hint" style="margin-top: 0.35rem;">
                    These timestamps are advanced automatically each time the sync runs.
                    If you ever need to reset them manually, you can do so via your database
                    (in the <code>sync_settings</code> table).
                </div>
            </div>
        </fieldset>

        <button type="submit">Save settings</button>
    </form>
</body>
</html>
