<?php
declare(strict_types=1);



$config = require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Auth.php';
Auth::requireLogin();
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
    // ensure one row per key
    $stmt = $pdo->prepare('DELETE FROM sync_settings WHERE setting_key = :key');
    $stmt->execute([':key' => $key]);

    $stmt = $pdo->prepare('INSERT INTO sync_settings (setting_key, setting_value) VALUES (:key, :value)');
    $stmt->execute([
        ':key'   => $key,
        ':value' => $value,
    ]);
}

try {
    $db  = Db::getInstance($config['db']);
    $pdo = $db->getConnection();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Settings error</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

// Defaults if nothing in DB
$defaults = [
    'notification_email'        => '',
    'deposit_bank_account_name' => 'TRINITY 2000 CHECKING',
    'income_account_name'       => 'OPERATING INCOME:WEEKLY OFFERINGS:PLEDGES',
    'stripe_fee_account_name'   => 'OPERATING EXPENSES:MINISTRY EXPENSES:PROCESSING FEES',
];

$saved = false;
$errors = [];

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $notificationEmail = trim($_POST['notification_email'] ?? '');
    $depositBank       = trim($_POST['deposit_bank_account_name'] ?? '');
    $incomeAccount     = trim($_POST['income_account_name'] ?? '');
    $stripeFeeAccount  = trim($_POST['stripe_fee_account_name'] ?? '');

    // basic validation
    if ($notificationEmail !== '' && !filter_var($notificationEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Notification email is not a valid email address.';
    }
    if ($depositBank === '') {
        $errors[] = 'Deposit bank account name is required.';
    }
    if ($incomeAccount === '') {
        $errors[] = 'Income account name is required.';
    }
    if ($stripeFeeAccount === '') {
        $errors[] = 'Stripe fee account name is required.';
    }

    if (empty($errors)) {
        try {
            set_setting($pdo, 'notification_email', $notificationEmail);
            set_setting($pdo, 'deposit_bank_account_name', $depositBank);
            set_setting($pdo, 'income_account_name', $incomeAccount);
            set_setting($pdo, 'stripe_fee_account_name', $stripeFeeAccount);
            $saved = true;
        } catch (Throwable $e) {
            $errors[] = 'Error saving settings: ' . $e->getMessage();
        }
    }

    // repopulate from POST if there were errors
    $current = [
        'notification_email'        => $notificationEmail,
        'deposit_bank_account_name' => $depositBank,
        'income_account_name'       => $incomeAccount,
        'stripe_fee_account_name'   => $stripeFeeAccount,
    ];
} else {
    // GET: load from DB or defaults
    $current = [];
    foreach ($defaults as $key => $def) {
        $val = get_setting($pdo, $key);
        $current[$key] = $val !== null ? $val : $def;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sync Settings</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 2rem;
        }
        h1 {
            margin-bottom: 1rem;
        }
        .ok {
            padding: 0.6rem 0.8rem;
            background: #e6ffed;
            border: 1px solid #b7eb8f;
            margin-bottom: 1rem;
        }
        .error {
            padding: 0.6rem 0.8rem;
            background: #ffecec;
            border: 1px solid #ffaeae;
            margin-bottom: 1rem;
        }
        label {
            font-weight: 600;
            display: block;
            margin-top: 1rem;
        }
        input[type="text"],
        input[type="email"] {
            width: 100%;
            max-width: 500px;
            padding: 0.4rem 0.5rem;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 0.95rem;
        }
        .help {
            font-size: 0.85rem;
            color: #666;
        }
        button {
            margin-top: 1.5rem;
            padding: 0.5rem 0.9rem;
            background: #1677ff;
            color: #fff;
            border: none;
            border-radius: 4px;
            font-size: 0.95rem;
            cursor: pointer;
        }
        button:hover {
            background: #0b5ed7;
        }
        a {
            color: #1677ff;
        }
    </style>
</head>
<body>

<h1>Sync Settings</h1>

<?php if ($saved && empty($errors)): ?>
    <div class="ok">
        Settings saved successfully.
    </div>
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

<form method="post" action="settings.php" novalidate>
    <label for="notification_email">Notification email</label>
    <input
        type="email"
        id="notification_email"
        name="notification_email"
        value="<?= htmlspecialchars($current['notification_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
        placeholder="you@example.com"
    />
    <div class="help">
        If set, sync errors can be emailed here (feature to be added next). Leave blank to disable email notifications.
    </div>

    <label for="deposit_bank_account_name">Deposit bank account name (QBO)</label>
    <input
        type="text"
        id="deposit_bank_account_name"
        name="deposit_bank_account_name"
        value="<?= htmlspecialchars($current['deposit_bank_account_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
    />
    <div class="help">
        Name of the bank account in QBO to receive deposits (e.g. <code>TRINITY 2000 CHECKING</code>).
    </div>

    <label for="income_account_name">Income account fully-qualified name (QBO)</label>
    <input
        type="text"
        id="income_account_name"
        name="income_account_name"
        value="<?= htmlspecialchars($current['income_account_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
    />
    <div class="help">
        Fully-qualified name of the income account for donations, including parents, e.g.
        <code>OPERATING INCOME:WEEKLY OFFERINGS:PLEDGES</code>.
    </div>

    <label for="stripe_fee_account_name">Stripe fee account fully-qualified name (QBO)</label>
    <input
        type="text"
        id="stripe_fee_account_name"
        name="stripe_fee_account_name"
        value="<?= htmlspecialchars($current['stripe_fee_account_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
    />
    <div class="help">
        Fully-qualified name of the expense account for Stripe fees, e.g.
        <code>OPERATING EXPENSES:MINISTRY EXPENSES:PROCESSING FEES</code>.
    </div>

    <button type="submit">Save settings</button>
</form>

<p style="margin-top:1.5rem;">
    <a href="index.php">&larr; Back to dashboard</a>
</p>

</body>
</html>
