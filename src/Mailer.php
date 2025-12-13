<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mailer
{
    private string $from;
    private string $logFile;
    private array $smtp;

    public function __construct(?string $from = null, array $smtpConfig = [])
    {
        if ($from) {
            $this->from = $from;
        } else {
            $host       = $_SERVER['SERVER_NAME'] ?? 'localhost';
            $this->from = 'no-reply@' . $host;
        }

        // Log file in the app root: /qbo-pco-sync/mail-debug.log
        $this->logFile = dirname(__DIR__) . '/mail-debug.log';

        // Normalize SMTP settings (all optional)
        $this->smtp = [
            'host'       => (string)($smtpConfig['smtp_host'] ?? getenv('MAIL_SMTP_HOST') ?: ''),
            'port'       => (int)($smtpConfig['smtp_port'] ?? getenv('MAIL_SMTP_PORT') ?: 587),
            'user'       => (string)($smtpConfig['smtp_user'] ?? getenv('MAIL_SMTP_USER') ?: ''),
            'pass'       => (string)($smtpConfig['smtp_pass'] ?? getenv('MAIL_SMTP_PASS') ?: ''),
            'encryption' => strtolower((string)($smtpConfig['smtp_encryption'] ?? getenv('MAIL_SMTP_ENCRYPTION') ?: 'tls')),
        ];
    }

    public function send(string $to, string $subject, string $body): void
    {
        $logLines = [];
        $logLines[] = sprintf("[%s] Attempting mail send", date('c'));
        $logLines[] = '  To:      ' . $to;
        $logLines[] = '  From:    ' . $this->from;
        $logLines[] = '  Subject: ' . $subject;
        $logLines[] = '  Mode:    ' . ($this->smtp['host'] !== '' ? 'smtp' : 'mail()');
        $logLines[] = '';
        @file_put_contents($this->logFile, implode("\n", $logLines) . "\n", FILE_APPEND);

        $sent = false;
        $status = 'not_sent';
        $errorMsg = null;

        if ($this->smtp['host'] !== '') {
            $autoload = dirname(__DIR__) . '/vendor/autoload.php';
            if (file_exists($autoload)) {
                require_once $autoload;
                try {
                    $mailer = new PHPMailer(true);
                    $mailer->isSMTP();
                    $mailer->Host       = $this->smtp['host'];
                    $mailer->Port       = $this->smtp['port'] > 0 ? $this->smtp['port'] : 587;
                    $mailer->SMTPAuth   = true;
                    $mailer->Username   = $this->smtp['user'];
                    $mailer->Password   = $this->smtp['pass'];
                    $enc = $this->smtp['encryption'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
                    $mailer->SMTPSecure = $enc;
                    $mailer->CharSet    = 'UTF-8';

                    $mailer->setFrom($this->from);
                    $mailer->addAddress($to);
                    $mailer->Subject = $subject;
                    $mailer->Body    = $body;

                    $mailer->send();
                    $sent = true;
                    $status = 'phpmailer_smtp_success';
                } catch (Exception $e) {
                    $status = 'phpmailer_smtp_error';
                    $errorMsg = $e->getMessage();
                }
            } else {
                $status = 'phpmailer_autoload_missing';
                $errorMsg = 'vendor/autoload.php not found; run composer install';
            }
        }

        // Fallback to mail() if SMTP not configured or PHPMailer not available.
        if (!$sent && $this->smtp['host'] === '') {
            $headers = [];
            $headers[] = 'From: ' . $this->from;
            $headers[] = 'Reply-To: ' . $this->from;
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $sent = mail($to, $subject, $body, implode("\r\n", $headers));
            $status = 'mail_function_' . ($sent ? 'success' : 'fail');
        }

        $resultLines = [];
        $resultLines[] = sprintf("[%s] Mail result: %s", date('c'), $status);
        if ($errorMsg) {
            $resultLines[] = '  Error: ' . $errorMsg;
        }
        $resultLines[] = str_repeat('-', 60);
        @file_put_contents($this->logFile, implode("\n", $resultLines) . "\n", FILE_APPEND);
    }
}
