<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

// Verify webhook authenticity using HMAC (PCO Webhooks).
$raw = file_get_contents('php://input');

// Support multiple secrets (one per subscription)
$secrets = [];
if (!empty($config['webhook_secrets']) && is_array($config['webhook_secrets'])) {
    foreach ($config['webhook_secrets'] as $s) {
        if (!empty($s)) {
            $secrets[] = (string)$s;
        }
    }
}

if (empty($secrets)) {
    http_response_code(500);
    echo 'Webhook secrets not configured.';
    exit;
}

$providedSig = $_SERVER['HTTP_X_PCO_WEBHOOKS_AUTHENTICITY'] ?? '';
$valid = false;
foreach ($secrets as $secret) {
    $expectedSig = hash_hmac('sha256', $raw, $secret);
    if (hash_equals($expectedSig, (string)$providedSig)) {
        $valid = true;
        break;
    }
}

if (!$valid) {
    http_response_code(403);
    echo 'Invalid webhook signature.';
    exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo 'Invalid JSON';
    exit;
}

$type = $payload['type'] ?? '';
$action = null;

// Routing for PCO Giving webhooks (event types from your subscriptions)
if (in_array($type, ['giving.v2.events.batch.created', 'giving.v2.events.batch.updated'], true)) {
    $action = 'run-batch-sync.php';
} elseif (in_array($type, ['giving.v2.events.donation.created', 'giving.v2.events.donation.updated'], true)) {
    $action = 'run-sync.php';
}

// If unknown type, accept but no action
if ($action === null) {
    http_response_code(202);
    echo 'Event ignored.';
    exit;
}

// Fire the appropriate sync endpoint with webhook secret for auth bypass.
// Assumes same host; adjust base URL if behind a proxy.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$url    = $scheme . '://' . $host . '/' . $action . '?webhook_secret=' . urlencode($secret);

// Non-blocking fire-and-forget (ignore response)
@file_get_contents($url);

http_response_code(202);
echo 'Sync triggered.';
