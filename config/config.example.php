<?php
// /qbo-pco-sync/config/config.php (example)
//
// Copy this file to config/config.php and fill in your secrets,
// or use public/setup.php to generate config/.env and config.php automatically.

// Lightweight .env loader (key=value, no interpolation)
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $envLines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($envLines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
        $k = trim($k);
        $v = trim($v, " \t\n\r\0\x0B\"'");
        if ($k !== '') {
            putenv($k . '=' . $v);
            $_ENV[$k] = $v;
        }
    }
}

function env_or_default(string $key, $default = null)
{
    $val = getenv($key);
    if ($val === false) {
        return $default;
    }
    return $val;
}

return [
    'db' => [
        'host'    => env_or_default('DB_HOST', ''),
        'name'    => env_or_default('DB_NAME', ''),
        'user'    => env_or_default('DB_USER', ''),
        'pass'    => env_or_default('DB_PASS', ''),
        'charset' => env_or_default('DB_CHARSET', 'utf8mb4'),
    ],

    'qbo' => [
        'client_id'     => env_or_default('QBO_CLIENT_ID', ''),
        'client_secret' => env_or_default('QBO_CLIENT_SECRET', ''),
        'redirect_uri'  => env_or_default('QBO_REDIRECT_URI', ''),
        'base_url'      => env_or_default('QBO_BASE_URL', 'https://quickbooks.api.intuit.com'),
        'environment'   => env_or_default('QBO_ENVIRONMENT', 'production'),
    ],

    'pco' => [
        'app_id'   => env_or_default('PCO_APP_ID', ''),
        'secret'   => env_or_default('PCO_SECRET', ''),
        'base_url' => env_or_default('PCO_BASE_URL', 'https://api.planningcenteronline.com'),
    ],
    'webhook_secrets' => [
        'giving.v2.events.batch.created'    => env_or_default('PCO_WEBHOOK_BATCH_CREATED', ''),
        'giving.v2.events.batch.updated'    => env_or_default('PCO_WEBHOOK_BATCH_UPDATED', ''),
        'giving.v2.events.donation.created' => env_or_default('PCO_WEBHOOK_DONATION_CREATED', ''),
        'giving.v2.events.donation.updated' => env_or_default('PCO_WEBHOOK_DONATION_UPDATED', ''),
    ],
    
    'app' => [
        'base_url'           => env_or_default('APP_BASE_URL', 'https://your-host/qbo-pco-sync/public'),
        'notification_email' => env_or_default('APP_NOTIFICATION_EMAIL', ''),
    ],
    'mail' => [
        'from'            => env_or_default('MAIL_FROM', ''),
        'smtp_host'       => env_or_default('MAIL_SMTP_HOST', ''),
        'smtp_port'       => (int)env_or_default('MAIL_SMTP_PORT', 587),
        'smtp_user'       => env_or_default('MAIL_SMTP_USER', ''),
        'smtp_pass'       => env_or_default('MAIL_SMTP_PASS', ''),
        'smtp_encryption' => env_or_default('MAIL_SMTP_ENCRYPTION', 'tls'), // tls or ssl
    ],

];
