<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$config = require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Db.php';

try {
    $db  = Db::getInstance($config['db']);
    $pdo = $db->getConnection();
} catch (Throwable $e) {
    echo '<h1>Error connecting to DB</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

$errors = [];
$done   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['password_confirm'] ?? '';

    if ($username === '') {
        $errors[] = 'Username is required.';
    }
    if ($password === '') {
        $errors[] = 'Password is required.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Password and confirmation do not match.';
    }

    if (empty($errors)) {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare('INSERT INTO app_users (username, password_hash) VALUES (:u, :p)');
            $stmt->execute([
                ':u' => $username,
                ':p' => $hash,
            ]);
            $done = true;
        } catch (Throwable $e) {
            $errors[] = 'Error inserting user: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Admin User</title>
    <style>
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 2rem; }
        .ok { padding: 0.6rem 0.8rem; background: #e6ffed; border: 1px solid #b7eb8f; margin-bottom: 1rem; }
        .error { padding: 0.6rem 0.8rem; background: #ffecec; border: 1px solid #ffaeae; margin-bottom: 1rem; }
        label { display:block; margin-top:1rem; font-weight:600; }
        input[type="text"], input[type="password"] {
            width: 100%; max-width: 400px; padding: 0.4rem 0.5rem;
            border: 1px solid #ccc; border-radius: 4px;
        }
        button { margin-top: 1rem; padding: 0.4rem 0.8rem; }
    </style>
</head>
<body>
<h1>Create Admin User</h1>

<?php if ($done): ?>
    <div class="ok">
        User created successfully. You can now delete this file <code>create_admin.php</code> from the server.
    </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="error">
        <ul>
            <?php foreach ($errors as $err): ?>
                <li><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post">
    <label for="username">Username</label>
    <input id="username" name="username" type="text" required>

    <label for="password">Password</label>
    <input id="password" name="password" type="password" required>

    <label for="password_confirm">Confirm password</label>
    <input id="password_confirm" name="password_confirm" type="password" required>

    <button type="submit">Create user</button>
</form>

</body>
</html>
