<?php
declare(strict_types=1);

// public/test-pco.php
$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../src/PcoClient.php';
require_once __DIR__ . '/../src/Auth.php';
Auth::requireLogin();

$error = null;
$funds = [];

try {
    $pco   = new PcoClient($config);
    $funds = $pco->listFunds();
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PCO Funds Test</title>
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
            max-width: 1100px;
            margin: 0 auto;
            padding: 2.4rem 1.25rem 3rem;
        }
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 1.2rem 1.25rem;
            box-shadow: 0 8px 30px rgba(0,0,0,0.18);
            margin-bottom: 1rem;
        }
        .hero {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 1.4rem 1.5rem;
            box-shadow: 0 10px 40px rgba(0,0,0,0.25);
            position: relative;
            overflow: hidden;
            margin-bottom: 1rem;
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
        .flash {
            padding: 0.85rem 1rem;
            border-radius: 10px;
            border: 1px solid var(--border);
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 0.75rem;
            align-items: center;
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
        .table-wrap {
            overflow: auto;
            border-radius: 10px;
            border: 1px solid var(--border);
            margin-top: 0.75rem;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 720px;
            background: rgba(255,255,255,0.01);
        }
        th, td {
            border-bottom: 1px solid rgba(255,255,255,0.08);
            padding: 0.65rem 0.75rem;
            vertical-align: top;
            font-size: 0.95rem;
            text-align: left;
        }
        th {
            color: var(--muted);
            font-weight: 700;
            background: rgba(255,255,255,0.03);
            position: sticky;
            top: 0;
            backdrop-filter: blur(8px);
            z-index: 1;
        }
        .tag {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.3rem 0.6rem;
            border-radius: 999px;
            background: rgba(46,168,255,0.18);
            color: #dbeeff;
            font-size: 0.85rem;
            border: 1px solid rgba(255,255,255,0.08);
        }
        .muted { color: var(--muted); font-size: 0.95rem; }
        .footnote { margin-top: 1.2rem; }
        a { color: var(--accent); }
    </style>
</head>
<body>
<div class="page">
    <div class="hero">
        <div class="eyebrow">Diagnostics</div>
        <h1>Planning Center Funds Test</h1>
        <p class="lede">Verify PCO credentials and list available funds for mapping.</p>
    </div>

    <?php if ($error): ?>
        <div class="card">
            <div class="flash error">
                <span class="tag">Issue</span>
                <div>Error talking to PCO:<br><code><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></code></div>
            </div>
            <p class="muted" style="margin-top:0.7rem;">
                Double-check your <code>pco.app_id</code> and <code>pco.secret</code> in <code>config.php</code>, and ensure your Personal Access Token has Giving API access.
            </p>
        </div>
    <?php else: ?>
        <div class="card">
            <p class="muted">PCO connection looks good. Here are the funds available in Planning Center Giving.</p>

            <?php if (empty($funds)): ?>
                <p class="muted"><em>No funds returned from PCO.</em></p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Fund ID</th>
                            <th>Name</th>
                            <th>Ledger Code</th>
                            <th>Visibility</th>
                            <th>Default?</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($funds as $fund): ?>
                            <?php
                                $id   = $fund['id'] ?? '';
                                $attr = $fund['attributes'] ?? [];
                            ?>
                            <tr>
                                <td><code><?= htmlspecialchars((string)$id, ENT_QUOTES, 'UTF-8') ?></code></td>
                                <td><?= htmlspecialchars((string)($attr['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string)($attr['ledger_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <?php if (!empty($attr['visibility'])): ?>
                                        <span class="tag"><?= htmlspecialchars((string)$attr['visibility'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= !empty($attr['default']) ? 'Yes' : 'No' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <p class="muted footnote">
        Use these Fund IDs to map to your QBO Classes &amp; Locations in the fund mapping screen before running syncs.
    </p>
</div>
</body>
</html>
