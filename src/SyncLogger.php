<?php
declare(strict_types=1);

class SyncLogger
{
    public static function start(PDO $pdo, \DateTimeInterface $windowStart, \DateTimeInterface $windowEnd): int
    {
        $stmt = $pdo->prepare('
            INSERT INTO sync_logs (started_at, window_start, window_end, status)
            VALUES (UTC_TIMESTAMP(), :ws, :we, :status)
        ');
        $stmt->execute([
            ':ws'     => $windowStart->format('Y-m-d H:i:s'),
            ':we'     => $windowEnd->format('Y-m-d H:i:s'),
            ':status' => 'success', // assume success until proven otherwise
        ]);

        return (int)$pdo->lastInsertId();
    }

    public static function finish(
        PDO $pdo,
        int $logId,
        string $status,
        int $donationsCount,
        int $depositsCount,
        ?string $message = null
    ): void {
        $stmt = $pdo->prepare('
            UPDATE sync_logs
               SET finished_at     = UTC_TIMESTAMP(),
                   status          = :status,
                   donations_count = :donations,
                   deposits_count  = :deposits,
                   message         = :message
             WHERE id = :id
             LIMIT 1
        ');
        $stmt->execute([
            ':status'    => $status,
            ':donations' => $donationsCount,
            ':deposits'  => $depositsCount,
            ':message'   => $message,
            ':id'        => $logId,
        ]);
    }
}
