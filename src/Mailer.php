<?php
declare(strict_types=1);

class Mailer
{
    private string $from;
    private string $logFile;

    public function __construct(?string $from = null)
    {
        if ($from) {
            $this->from = $from;
        } else {
            $host       = $_SERVER['SERVER_NAME'] ?? 'localhost';
            $this->from = 'no-reply@' . $host;
        }

        // Log file in the app root: /qbo-pco-sync/mail-debug.log
        $this->logFile = dirname(__DIR__) . '/mail-debug.log';
    }

    public function send(string $to, string $subject, string $body): void
    {
        // Build headers
        $headers = [];
        $headers[] = 'From: ' . $this->from;
        $headers[] = 'Reply-To: ' . $this->from;
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';

        // Log before sending
        $logLines = [];
        $logLines[] = sprintf(
            "[%s] Attempting mail send",
            date('c')
        );
        $logLines[] = '  To:      ' . $to;
        $logLines[] = '  From:    ' . $this->from;
        $logLines[] = '  Subject: ' . $subject;
        $logLines[] = '';

        @file_put_contents($this->logFile, implode("\n", $logLines) . "\n", FILE_APPEND);

        // Actually send email (no @ suppression so we get a real true/false)
        $success = mail($to, $subject, $body, implode("\r\n", $headers));

        // Log result
        $resultLines = [];
        $resultLines[] = sprintf(
            "[%s] mail() returned: %s",
            date('c'),
            $success ? 'true' : 'false'
        );
        $resultLines[] = str_repeat('-', 60);

        @file_put_contents($this->logFile, implode("\n", $resultLines) . "\n", FILE_APPEND);
    }
}
