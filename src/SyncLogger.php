<?php
declare(strict_types=1);

class SyncLogger
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function start(string $syncType): int
    {
        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $stmt = $this->pdo->prepare(
            'INSERT INTO sync_logs (sync_type, started_at, status) 
             VALUES (:type, :started_at, "success")'
        );
        $stmt->execute([
            ':type'        => $syncType,
            ':started_at'  => $nowUtc->format('Y-m-d H:i:s'),
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function finish(
        int $id,
        string $status = 'success',
        ?string $summary = null,
        ?string $details = null
    ): void {
        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $stmt = $this->pdo->prepare(
            'UPDATE sync_logs
                SET finished_at = :finished_at,
                    status = :status,
                    summary = :summary,
                    details = :details
              WHERE id = :id'
        );
        $stmt->execute([
            ':finished_at' => $nowUtc->format('Y-m-d H:i:s'),
            ':status'      => $status,
            ':summary'     => $summary,
            ':details'     => $details,
            ':id'          => $id,
        ]);
    }
}
