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

$errors     = [];
$isFirstRun = false;

try {
    $db  = Db::getInstance($config['db']);
    $pdo = $db->getConnection();
    $userCount = (int)$pdo->query('SELECT COUNT(*) FROM app_users')->fetchColumn();
    $isFirstRun = ($userCount === 0);
} catch (Throwable $e) {
    $errors[] = 'Database error: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['password_confirm'] ?? '';

    if ($username === '' || $password === '') {
        $errors[] = 'Username and password are required.';
    } elseif ($isFirstRun && $password !== $confirm) {
        $errors[] = 'Password and confirmation do not match.';
    } else {
        try {
            if ($isFirstRun) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO app_users (username, password_hash, is_admin) VALUES (:u, :p, 1)');
                $stmt->execute([':u' => $username, ':p' => $hash]);

                $_SESSION['user_id']   = (int)$pdo->lastInsertId();
                $_SESSION['username']  = $username;
                $_SESSION['is_admin']  = true;
                header('Location: index.php');
                exit;
            } else {
                $stmt = $pdo->prepare('SELECT id, username, password_hash, is_admin FROM app_users WHERE username = :u LIMIT 1');
                $stmt->execute([':u' => $username]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$user || !password_verify($password, $user['password_hash'])) {
                    $errors[] = 'Invalid username or password.';
                } else {
                    $_SESSION['user_id']  = (int)$user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['is_admin'] = (bool)$user['is_admin'];
                    header('Location: index.php');
                    exit;
                }
            }
        } catch (Throwable $e) {
            $errors[] = $isFirstRun
                ? 'Error creating admin user: ' . $e->getMessage()
                : 'Error checking login: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $isFirstRun ? 'Set up admin' : 'QBO/PCO Sync Login' ?></title>
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
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 1.4rem 1.5rem;
            box-shadow: 0 10px 40px rgba(0,0,0,0.25);
            width: 100%;
            max-width: 420px;
            position: relative;
            overflow: hidden;
        }
        .panel::after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 30% 40%, rgba(46,168,255,0.15), transparent 35%),
                        radial-gradient(circle at 80% 10%, rgba(57,217,138,0.12), transparent 35%);
            pointer-events: none;
        }
        .panel > * { position: relative; z-index: 1; }
        h1 { margin: 0 0 0.35rem; font-size: 1.5rem; letter-spacing: -0.01em; }
        .lede { color: var(--muted); margin: 0 0 0.65rem; line-height: 1.5; font-size: 0.95rem; }
        label { display:block; margin-top:0.7rem; font-weight:700; letter-spacing:-0.01em; }
        input[type="text"], input[type="password"] {
            width: 100%; padding: 0.65rem 0.75rem;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.14);
            background: rgba(255,255,255,0.04);
            color: var(--text);
            font-size: 1rem;
        }
        input:focus {
            outline: 2px solid rgba(46,168,255,0.4);
            border-color: rgba(46,168,255,0.45);
        }
        .hint { font-size: 0.9rem; color: var(--muted); margin-top: 0.35rem; }
        .error {
            background: rgba(255, 122, 122, 0.12);
            border: 1px solid rgba(255, 122, 122, 0.35);
            padding: 0.75rem 0.85rem;
            border-radius: 10px;
            margin-bottom: 0.85rem;
        }
        button {
            margin-top:1rem;
            padding:0.65rem 1rem;
            border:none;
            border-radius:10px;
            background: linear-gradient(135deg, var(--accent), var(--accent-strong));
            color:#0b1324;
            font-weight:700;
            cursor:pointer;
            box-shadow: 0 10px 25px rgba(13,122,223,0.35);
            transition: transform 120ms ease, box-shadow 120ms ease;
        }
        button:hover {
            transform: translateY(-1px);
            box-shadow: 0 14px 30px rgba(13,122,223,0.4);
        }
        .small-link {
            margin-top: 0.75rem;
            display: inline-block;
            color: var(--muted);
            text-decoration: none;
        }
        .small-link:hover { color: #dff1ff; }
    </style>
</head>
<body>
<div class="panel">
    <h1><?= $isFirstRun ? 'Create first admin user' : 'QBO / PCO Sync Login' ?></h1>
    <p class="lede"><?= $isFirstRun ? 'Set up your initial admin account to get started.' : 'Sign in to manage sync settings and runs.' ?></p>

    <?php if (!empty($errors)): ?>
        <div class="error">
            <?php foreach ($errors as $err): ?>
                <div><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" action="login.php">
        <label for="username">Username</label>
        <input id="username" name="username" type="text" autocomplete="username" required>

        <label for="password"><?= $isFirstRun ? 'Admin password' : 'Password' ?></label>
        <input id="password" name="password" type="password" autocomplete="<?= $isFirstRun ? 'new-password' : 'current-password' ?>" required>

        <?php if ($isFirstRun): ?>
            <label for="password_confirm">Confirm password</label>
            <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required>
            <div class="hint">This will create the first admin and log you in.</div>
        <?php endif; ?>

        <button type="submit"><?= $isFirstRun ? 'Create admin and continue' : 'Log in' ?></button>
    </form>
    <?php if (!$isFirstRun): ?>
        <a class="small-link" href="logout.php">Need to switch accounts? Log out.</a>
    <?php endif; ?>
</div>
</body>
</html>
