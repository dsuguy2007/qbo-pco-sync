<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

// Verify webhook authenticity using HMAC (PCO Webhooks).
$secret = $config['webhook_secret'] ?? null;
$raw    = file_get_contents('php://input');

if (!$secret) {
    http_response_code(500);
    echo 'Webhook secret not configured.';
    exit;
}

$providedSig = $_SERVER['HTTP_X_PCO_WEBHOOKS_AUTHENTICITY'] ?? '';
$expectedSig = hash_hmac('sha256', $raw, $secret);

if (!hash_equals($expectedSig, (string)$providedSig)) {
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

// Basic routing by event type name (adjust as needed to match PCO webhook payloads)
if ($type === 'batch_committed') {
    $action = 'run-batch-sync.php';
} elseif ($type === 'donation_completed') {
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
