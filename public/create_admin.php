<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Auth.php';

Auth::startSession();

try {
    $db  = Db::getInstance($config['db']);
    $pdo = $db->getConnection();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Error connecting to DB</h1>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    exit;
}

$userCount = (int)$pdo->query('SELECT COUNT(*) FROM app_users')->fetchColumn();

// If users exist, ensure the current session is an admin (refresh from DB to avoid stale sessions).
if ($userCount > 0) {
    Auth::requireLogin();
    try {
        $stmt = $pdo->prepare('SELECT is_admin FROM app_users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $_SESSION['is_admin'] = !empty($row['is_admin']);
    } catch (Throwable $e) {
        http_response_code(500);
        echo '<h1>Error checking admin status</h1>';
        echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
        exit;
    }
    if (empty($_SESSION['is_admin'])) {
        http_response_code(403);
        echo 'Admin privileges are required to access this page.';
        exit;
    }
}

$errors = [];
$done   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['password_confirm'] ?? '';
    $isAdmin  = isset($_POST['is_admin']) ? 1 : 0;

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
            if ($userCount === 0) {
                $isAdmin = 1;
            }

            $stmt = $pdo->prepare('INSERT INTO app_users (username, password_hash, is_admin) VALUES (:u, :p, :a)');
            $stmt->execute([
                ':u' => $username,
                ':p' => $hash,
                ':a' => $isAdmin,
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
    <title>Create Admin/User</title>
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
        }
        * { box-sizing: border-box; }
        .page {
            max-width: 760px;
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
            line-height: 1.6;
        }
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 1.2rem 1.25rem;
            box-shadow: 0 8px 30px rgba(0,0,0,0.18);
            margin-top: 1rem;
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
        form {
            margin-top: 0.5rem;
            display: grid;
            gap: 0.75rem;
        }
        label {
            font-weight: 700;
            letter-spacing: -0.01em;
        }
        input[type="text"], input[type="password"] {
            width: 100%;
            max-width: 420px;
            padding: 0.65rem 0.75rem;
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
            cursor: pointer;
            border: none;
        }
        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 14px 30px rgba(13,122,223,0.4);
        }
        .hint {
            color: var(--muted);
            font-size: 0.95rem;
        }
        .nav-link {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            color: var(--text);
            text-decoration: none;
            margin-top: 0.5rem;
        }
        .nav-link:hover {
            color: #dff1ff;
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
            .btn { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="hero">
        <div class="eyebrow">Admin</div>
        <h1>Create user</h1>
        <p class="lede"><?= $userCount === 0 ? 'Set up the first admin account.' : 'Add another user (admins only).' ?></p>
    </div>

    <?php if ($done): ?>
        <div class="flash success">
            <span class="tag">Saved</span>
            <div>User created successfully.</div>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="flash error">
            <span class="tag">Issue</span>
            <div>
                <ul>
                    <?php foreach ($errors as $err): ?>
                        <li><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <form method="post">
            <div>
                <label for="username">Username</label>
                <input id="username" name="username" type="text" required>
            </div>

            <div>
                <label for="password">Password</label>
                <input id="password" name="password" type="password" required>
            </div>

            <div>
                <label for="password_confirm">Confirm password</label>
                <input id="password_confirm" name="password_confirm" type="password" required>
            </div>

            <?php if ($userCount > 0): ?>
                <label style="display:flex;align-items:center;gap:0.45rem;font-weight:600;">
                    <input type="checkbox" name="is_admin" value="1">
                    Make this user an admin
                </label>
            <?php endif; ?>

            <div class="hint">Use a strong password. Usernames must be unique.</div>

            <button type="submit" class="btn">Create user</button>
        </form>
        <a class="nav-link" href="index.php">&larr; Back to dashboard</a>
    </div>
    <div class="footer">
        &copy; <?= date('Y') ?> Rev. Tommy Sheppard • <a href="help.php">Help</a>
    </div>
</div>
</body>
</html>
