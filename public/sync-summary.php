<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Auth.php';
Auth::requireLogin();

header('Content-Type: application/json');

function load_sync_summary(PDO $pdo, string $key): ?array
{
    try {
        $stmt = $pdo->prepare('SELECT setting_value FROM sync_settings WHERE setting_key = :key ORDER BY id DESC LIMIT 1');
        $stmt->execute([':key' => $key]);
        $val = $stmt->fetchColumn();
        if ($val) {
            $decoded = json_decode((string)$val, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    } catch (Throwable $e) {
        return null;
    }
    return null;
}

try {
    $db  = Db::getInstance($config['db']);
    $pdo = $db->getConnection();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db_error', 'detail' => $e->getMessage()]);
    exit;
}

$out = [
    'stripe'        => load_sync_summary($pdo, 'last_stripe_sync_summary'),
    'batch'         => load_sync_summary($pdo, 'last_batch_sync_summary'),
    'registrations' => load_sync_summary($pdo, 'last_registrations_sync_summary'),
];

echo json_encode($out);
