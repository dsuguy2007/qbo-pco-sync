<?php
declare(strict_types=1);



$config = require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Auth.php';

Auth::startSession();

// If already logged in, go to dashboard
if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $errors[] = 'Username and password are required.';
    } else {
        try {
            $db  = Db::getInstance($config['db']);
            $pdo = $db->getConnection();

            $stmt = $pdo->prepare('SELECT id, username, password_hash FROM app_users WHERE username = :u LIMIT 1');
            $stmt->execute([':u' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !password_verify($password, $user['password_hash'])) {
                $errors[] = 'Invalid username or password.';
            } else {
                $_SESSION['user_id']  = (int)$user['id'];
                $_SESSION['username'] = $user['username'];
                header('Location: index.php');
                exit;
            }
        } catch (Throwable $e) {
            $errors[] = 'Error checking login: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>QBO/PCO Sync Login</title>
    <style>
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 2rem; }
        .box { max-width: 400px; margin: 3rem auto; border: 1px solid #ddd; padding: 1.5rem; border-radius: 6px; }
        h1 { font-size: 1.3rem; margin-bottom: 1rem; }
        label { display:block; margin-top:0.7rem; font-weight:600; }
        input[type="text"], input[type="password"] {
            width: 100%; padding: 0.4rem 0.5rem;
            border:1px solid #ccc; border-radius:4px;
        }
        .error { background:#ffecec; border:1px solid #ffaeae; padding:0.6rem 0.8rem; margin-bottom:1rem; }
        button { margin-top:1rem; padding:0.4rem 0.8rem; border:none; border-radius:4px; background:#1677ff; color:#fff; cursor:pointer; }
        button:hover { background:#0b5ed7; }
    </style>
</head>
<body>
<div class="box">
    <h1>QBO / PCO Sync Login</h1>

    <?php if (!empty($errors)): ?>
        <div class="error">
            <?php foreach ($errors as $err): ?>
                <div><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" action="login.php">
        <label for="username">Username</label>
        <input id="username" name="username" type="text" autocomplete="username">

        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password">

        <button type="submit">Log in</button>
    </form>
</div>
</body>
</html>
