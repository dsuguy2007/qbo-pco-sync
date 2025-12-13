<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../src/Mailer.php';

$to      = $config['app']['notification_email'] ?? '';
$from    = $config['mail']['from'] ?? '';
$mailCfg = $config['mail'] ?? [];

if ($to === '') {
    http_response_code(500);
    echo 'Notification email (APP_NOTIFICATION_EMAIL) is not set.';
    exit;
}

$mailer = new Mailer($from, $mailCfg);
$subject = 'Test email from qbo-pco-sync';
$body    = "This is a test email from qbo-pco-sync to confirm SMTP configuration.\nSent at: " . date('c');

$mailer->send($to, $subject, $body);

echo 'Test email attempted to ' . htmlspecialchars($to, ENT_QUOTES, 'UTF-8') . '. Check your inbox (and spam) or mail-debug.log for details.';
