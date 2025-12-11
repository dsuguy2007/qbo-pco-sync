<?php
declare(strict_types=1);

class Mailer
{
    private string $from;

    public function __construct(?string $from = null)
    {
        if ($from) {
            $this->from = $from;
        } else {
            $host       = $_SERVER['SERVER_NAME'] ?? 'localhost';
            $this->from = 'no-reply@' . $host;
        }
    }

    public function send(string $to, string $subject, string $body): void
    {
        $headers = [];
        $headers[] = 'From: ' . $this->from;
        $headers[] = 'Reply-To: ' . $this->from;
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';

        // Best-effort; we intentionally ignore the return value
        @mail($to, $subject, $body, implode("\r\n", $headers));
    }
}
