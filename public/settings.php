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

        // Registrations sync
        $regDepositName  = trim((string)($_POST['reg_deposit_bank_account_name'] ?? ''));
        $regIncomeName   = trim((string)($_POST['reg_income_account_name'] ?? ''));
        $regClassName    = trim((string)($_POST['reg_class_name'] ?? ''));
        $regLocationName = trim((string)($_POST['reg_location_name'] ?? ''));

        if ($regDepositName !== '') {
            set_setting($pdo, 'reg_deposit_bank_account_name', $regDepositName);
        }
        if ($regIncomeName !== '') {
            set_setting($pdo, 'reg_income_account_name', $regIncomeName);
        }
        set_setting($pdo, 'reg_class_name', $regClassName);
        set_setting($pdo, 'reg_location_name', $regLocationName);

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

// Registrations settings
$regDepositBankName = get_setting($pdo, 'reg_deposit_bank_account_name')
    ?? $depositBankName;
$regIncomeAccountName = get_setting($pdo, 'reg_income_account_name')
    ?? $incomeAccountName;
$regClassName = get_setting($pdo, 'reg_class_name') ?? '';
$regLocationName = get_setting($pdo, 'reg_location_name') ?? '';

// Last sync windows (read-only info)
$lastStripeCompletedAt = get_setting($pdo, 'last_completed_at');
$lastBatchCompletedAt  = get_setting($pdo, 'last_batch_sync_completed_at');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Settings - PCO &harr; QBO Sync</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Manrope:wght@500;700&display=swap');
        :root {
            --bg: #0b1224;
            --panel: rgba(15, 25, 46, 0.78);
            --card: rgba(22, 32, 55, 0.9);
            --border: rgba(255, 255, 255, 0.08);
            --text: #e9eef7;
            --muted: #9daccc;
            --accent: #2ea8ff;
            --accent-strong: #0d7adf;
            --success: #39d98a;
            --warn: #f2c94c;
            --error: #ff7a7a;
        }
        body {
            font-family: 'Manrope', 'Segoe UI', sans-serif;
            margin: 0;
            background: radial-gradient(circle at 15% 20%, rgba(46, 168, 255, 0.12), transparent 25%),
                        radial-gradient(circle at 85% 10%, rgba(57, 217, 138, 0.15), transparent 22%),
                        radial-gradient(circle at 70% 70%, rgba(242, 201, 76, 0.08), transparent 30%),
                        var(--bg);
            color: var(--text);
            min-height: 100vh;
        }
        * { box-sizing: border-box; }
        .page {
            max-width: 1050px;
            margin: 0 auto;
            padding: 2.4rem 1.25rem 3rem;
        }
        .hero {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 1.4rem 1.5rem;
            box-shadow: 0 10px 40px rgba(0,0,0,0.25);
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .hero::after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 30% 40%, rgba(46,168,255,0.15), transparent 35%),
                        radial-gradient(circle at 80% 10%, rgba(57,217,138,0.12), transparent 35%);
            pointer-events: none;
        }
        .hero > * { position: relative; z-index: 1; }
        .eyebrow {
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: var(--muted);
            font-size: 0.8rem;
            margin-bottom: 0.25rem;
        }
        h1 {
            margin: 0 0 0.35rem;
            font-size: 2rem;
            letter-spacing: -0.01em;
        }
        .lede {
            color: var(--muted);
            margin: 0;
            max-width: 58ch;
            line-height: 1.6;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.65rem 1rem;
            background: linear-gradient(135deg, var(--accent), var(--accent-strong));
            color: #0b1324;
            font-weight: 700;
            text-decoration: none;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.08);
            box-shadow: 0 10px 25px rgba(13,122,223,0.35);
            transition: transform 120ms ease, box-shadow 120ms ease, background 150ms ease;
        }
        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 14px 30px rgba(13,122,223,0.4);
        }
        .btn.secondary {
            background: transparent;
            color: var(--text);
            border: 1px solid var(--border);
            box-shadow: none;
        }
        .flash {
            margin-top: 1rem;
            padding: 0.85rem 1rem;
            border-radius: 10px;
            border: 1px solid var(--border);
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 0.75rem;
            align-items: center;
        }
        .flash.success {
            background: rgba(57, 217, 138, 0.12);
            border-color: rgba(57, 217, 138, 0.35);
        }
        .flash.error {
            background: rgba(255, 122, 122, 0.12);
            border-color: rgba(255, 122, 122, 0.35);
        }
        .flash .tag {
            background: rgba(255, 255, 255, 0.06);
            padding: 0.35rem 0.65rem;
            border-radius: 999px;
            font-size: 0.85rem;
            border: 1px solid var(--border);
        }
        .form-stack {
            margin-top: 1.5rem;
            display: grid;
            gap: 1rem;
        }
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 1.2rem 1.25rem;
            box-shadow: 0 8px 30px rgba(0,0,0,0.18);
        }
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 0.85rem;
        }
        .section-title {
            margin: 0;
            font-size: 1.1rem;
            letter-spacing: -0.01em;
        }
        .section-sub {
            margin: 0;
            color: var(--muted);
            font-size: 0.95rem;
        }
        .field {
            display: grid;
            gap: 0.3rem;
            margin-bottom: 0.85rem;
        }
        .field label {
            font-weight: 700;
            letter-spacing: -0.01em;
        }
        .field input[type="text"],
        .field input[type="email"] {
            width: 100%;
            max-width: 520px;
            padding: 0.65rem 0.75rem;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.14);
            background: rgba(255,255,255,0.04);
            color: var(--text);
            font-size: 1rem;
        }
        .field input:focus {
            outline: 2px solid rgba(46,168,255,0.4);
            border-color: rgba(46,168,255,0.45);
        }
        .hint {
            font-size: 0.9rem;
            color: var(--muted);
            line-height: 1.45;
        }
        .checkbox-row {
            display: grid;
            gap: 0.35rem;
            margin-bottom: 0.7rem;
        }
        .checkbox-row label {
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            letter-spacing: -0.01em;
        }
        .readonly-values {
            display: grid;
            gap: 0.4rem;
            font-size: 0.95rem;
            color: var(--text);
        }
        .readonly-values code {
            font-size: 0.9rem;
            color: #d7e8ff;
        }
        .actions {
            display: flex;
            justify-content: flex-end;
        }
        button[type="submit"] {
            border: none;
            cursor: pointer;
            font-size: 1rem;
        }
        .footer {
            margin-top: 1.4rem;
            text-align: center;
            color: var(--muted);
            font-size: 0.9rem;
        }
        .footer a { color: var(--accent); text-decoration: none; }
        .footer a:hover { color: #dff1ff; }
        @media (max-width: 720px) {
            .hero { padding: 1.2rem 1.1rem; }
            .section-header { align-items: flex-start; }
            .btn.secondary { width: 100%; justify-content: center; }
            .actions { justify-content: stretch; }
            .actions .btn { width: 100%; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="hero">
        <div>
            <div class="eyebrow">Configuration</div>
            <h1>Settings</h1>
            <p class="lede">Tune notifications and QuickBooks targets so syncs land exactly where you want them.</p>
        </div>
        <a class="btn secondary" href="index.php">&larr; Back to dashboard</a>
    </div>

    <?php if ($message): ?>
        <div class="flash success">
            <span class="tag">Saved</span>
            <div><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="flash error">
            <span class="tag">Issue</span>
            <div><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
    <?php endif; ?>

    <form method="post" class="form-stack">
        <div class="card">
            <div class="section-header">
                <div>
                    <p class="eyebrow" style="margin-bottom: 0.35rem;">Notifications</p>
                    <p class="section-title">Alert routing</p>
                    <p class="section-sub">Where to send sync errors or partial successes.</p>
                </div>
            </div>
            <div class="field">
                <label for="notification_email">Error / warning notification email</label>
                <input
                    type="email"
                    id="notification_email"
                    name="notification_email"
                    value="<?php echo htmlspecialchars($notificationEmail, ENT_QUOTES, 'UTF-8'); ?>"
                >
                <div class="hint">
                    If set, the app will send an email when a Stripe or batch sync finishes with errors or partial success.
                    Leave blank to disable email notifications.
                </div>
            </div>
        </div>

        <div class="card">
            <div class="section-header">
                <div>
                    <p class="eyebrow" style="margin-bottom: 0.35rem;">Stripe payout sync</p>
                    <p class="section-title">Online donations</p>
                    <p class="section-sub">Control where Stripe payout deposits and fee lines are booked.</p>
                </div>
            </div>
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
                    Used for gross donation lines (for example: <code>OPERATING INCOME:WEEKLY OFFERINGS:PLEDGES</code>).
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
                    Used for the negative fee lines (for example: <code>OPERATING EXPENSES:MINISTRY EXPENSES:PROCESSING FEES</code>).
                </div>
            </div>
        </div>

        <div class="card">
            <div class="section-header">
                <div>
                    <p class="eyebrow" style="margin-bottom: 0.35rem;">Batch sync</p>
                    <p class="section-title">Committed PCO Giving batches</p>
                    <p class="section-sub">Enable and route deposits created from committed batches.</p>
                </div>
            </div>

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
                    When enabled, the batch sync will create deposits in QBO for committed batches, grouping by fund and applying
                    the correct QBO Class and Location based on your fund mapping.
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
                    Bank account used for deposits created from committed batches. Defaults to the Stripe deposit account if left the same.
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
                    Income account used for gross donation lines from committed batches. Defaults to the Stripe income account if left the same.
                </div>
            </div>
        </div>

        <div class="card">
            <div class="section-header">
                <div>
                    <p class="eyebrow" style="margin-bottom: 0.35rem;">Registrations sync</p>
                    <p class="section-title">Event payments (Registrations)</p>
                    <p class="section-sub">Default QBO targets for PCO Registrations payments.</p>
                </div>
            </div>

            <div class="field">
                <label for="reg_deposit_bank_account_name">Registrations deposit bank account (QBO name)</label>
                <input
                    type="text"
                    id="reg_deposit_bank_account_name"
                    name="reg_deposit_bank_account_name"
                    value="<?php echo htmlspecialchars($regDepositBankName, ENT_QUOTES, 'UTF-8'); ?>"
                >
                <div class="hint">Bank account used for deposits created from Registrations payments.</div>
            </div>

            <div class="field">
                <label for="reg_income_account_name">Registrations income account (QBO name)</label>
                <input
                    type="text"
                    id="reg_income_account_name"
                    name="reg_income_account_name"
                    value="<?php echo htmlspecialchars($regIncomeAccountName, ENT_QUOTES, 'UTF-8'); ?>"
                >
                <div class="hint">Income account for gross registration payments.</div>
            </div>

            <div class="field">
                <label for="reg_class_name">Registrations Class (QBO name, optional)</label>
                <input
                    type="text"
                    id="reg_class_name"
                    name="reg_class_name"
                    value="<?php echo htmlspecialchars($regClassName, ENT_QUOTES, 'UTF-8'); ?>"
                >
                <div class="hint">Optional Class to apply to registrations lines.</div>
            </div>

            <div class="field">
                <label for="reg_location_name">Registrations Location (QBO Department name, optional)</label>
                <input
                    type="text"
                    id="reg_location_name"
                    name="reg_location_name"
                    value="<?php echo htmlspecialchars($regLocationName, ENT_QUOTES, 'UTF-8'); ?>"
                >
                <div class="hint">Optional Location/Department to apply to registrations deposits.</div>
            </div>

            <div class="hint">Stripe fees for registrations will use the existing Stripe fee expense account.</div>
        </div>

        <div class="card">
            <div class="section-header">
                <div>
                    <p class="eyebrow" style="margin-bottom: 0.35rem;">Sync windows</p>
                    <p class="section-title">Read-only</p>
                    <p class="section-sub">Reference for the most recent sync windows.</p>
                </div>
            </div>
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
                <div class="hint">
                    These timestamps are advanced automatically each time the sync runs. If you ever need to reset them manually,
                    you can do so via your database (in the <code>sync_settings</code> table).
                </div>
            </div>
        </div>

        <div class="actions">
            <button type="submit" class="btn">Save settings</button>
        </div>
    </form>
    <div class="footer">
        &copy; <?= date('Y') ?> Rev. Tommy Sheppard <a href="help.php">Help</a>
    </div>
</div>
</body>
</html>
