<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

// Verify webhook authenticity using HMAC (PCO Webhooks).
$raw = file_get_contents('php://input');
$rawLen = strlen($raw);

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

$providedSig   = $_SERVER['HTTP_X_PCO_WEBHOOKS_AUTHENTICITY'] ?? '';
$valid         = false;
$matchedSecret = null;
foreach ($secrets as $secret) {
    $expectedSig = hash_hmac('sha256', $raw, $secret);
    if (hash_equals($expectedSig, (string)$providedSig)) {
        $valid         = true;
        $matchedSecret = $secret;
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
    log_debug_line("invalid_json raw_len={$rawLen} raw_sample=" . substr($raw, 0, 500));
    http_response_code(400);
    echo 'Invalid JSON';
    exit;
}

$type = $payload['type'] ?? ($payload['event']['type'] ?? ($payload['data']['type'] ?? ''));
// PCO Webhooks wrap the event inside data[0].attributes.name and payload string
if ($type === '' && isset($payload['data'][0]['attributes']['name'])) {
    $type = (string)$payload['data'][0]['attributes']['name'];
}
$actions = [];

// Routing for PCO Giving webhooks (event types from your subscriptions)
if (in_array($type, ['giving.v2.events.batch.created', 'giving.v2.events.batch.updated'], true)) {
    $actions[] = 'run-batch-sync.php';
    // Also trigger registrations sync when batches commit
    $actions[] = 'run-registrations-sync.php';
} elseif (in_array($type, ['giving.v2.events.donation.created', 'giving.v2.events.donation.updated'], true)) {
    $actions[] = 'run-sync.php';
}

// If unknown type, accept but no action
if (empty($actions)) {
    http_response_code(202);
    echo 'Event ignored.';
    exit;
}

// Fire the appropriate sync endpoint with webhook secret for auth bypass.
// Prefer configured base_url; otherwise derive from the current request.
$baseUrl = '';
if (!empty($config['app']['base_url'])) {
    $baseUrl = rtrim((string)$config['app']['base_url'], '/');
} else {
    $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $reqDir   = rtrim(dirname($_SERVER['REQUEST_URI'] ?? '/'), '/');
    $baseUrl  = $scheme . '://' . $host . ($reqDir !== '' ? $reqDir : '');
}

$urls = [];
foreach ($actions as $action) {
    $urls[] = rtrim($baseUrl, '/') . '/' . $action . '?webhook_secret=' . urlencode((string)$matchedSecret);
}

/**
 * Trigger sync in a best-effort, short-lived call (non-blocking for PCO).
 */
function trigger_sync(string $url): array
{
    // Prefer curl if available
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => ['User-Agent: PCO-Webhook-Relay'],
            CURLOPT_SSL_VERIFYPEER => false, // loopback/self-signed safe for internal call
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body  = curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = $errno ? curl_error($ch) : '';
        $code  = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [
            'ok'    => ($errno === 0 && $code >= 200 && $code < 500),
            'code'  => $code,
            'error' => $errno ? $err : '',
            'body'  => $body !== false ? $body : '',
        ];
    }

    // Fallback: file_get_contents with short timeout
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => 3,
            'header'  => "User-Agent: PCO-Webhook-Relay\r\n",
        ],
    ]);
    $result = @file_get_contents($url, false, $ctx);
    $meta   = $http_response_header ?? [];
    $code   = 0;
    foreach ($meta as $line) {
        if (preg_match('#^HTTP/\\S+\\s+(\\d{3})#', $line, $m)) {
            $code = (int)$m[1];
            break;
        }
    }
    return [
        'ok'    => $result !== false && $code >= 200 && $code < 500,
        'code'  => $code,
        'error' => $result === false ? 'stream_call_failed' : '',
        'body'  => $result !== false ? $result : '',
    ];
}

$hadError = false;
$logLines = [];
foreach ($urls as $url) {
    $triggerResult = trigger_sync($url);
    if (!$triggerResult['ok']) {
        $hadError = true;
        $msg = '[pco-webhook] Failed to trigger sync endpoint: ' . $url .
            ' code=' . ($triggerResult['code'] ?? 0) .
            ' err=' . ($triggerResult['error'] ?? 'n/a');
        error_log($msg);
    }
    $logLines[] = sprintf(
        '[%s] type=%s url=%s code=%s err=%s',
        (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
        $type,
        $url,
        $triggerResult['code'] ?? 'n/a',
        $triggerResult['error'] ?? ''
    );
}

// Append webhook activity log
$logFile = __DIR__ . '/../logs/webhooks.log';
if (!is_dir(dirname($logFile))) {
    @mkdir(dirname($logFile), 0775, true);
}
@file_put_contents($logFile, implode("\n", $logLines) . "\n", FILE_APPEND | LOCK_EX);

http_response_code(202);
echo $hadError ? 'Sync triggered with errors.' : 'Sync triggered.';
