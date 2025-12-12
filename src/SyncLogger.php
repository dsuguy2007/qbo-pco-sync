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
        $stmt = $this->pdo->prepare(
            'INSERT INTO sync_logs (sync_type, started_at, status) 
             VALUES (:type, UTC_TIMESTAMP(), "success")'
        );
        $stmt->execute([
            ':type'        => $syncType,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function finish(
        int $id,
        string $status = 'success',
        ?string $summary = null,
        ?string $details = null
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE sync_logs
                SET finished_at = UTC_TIMESTAMP(),
                    status = :status,
                    summary = :summary,
                    details = :details
              WHERE id = :id'
        );
        $stmt->execute([
            ':status'      => $status,
            ':summary'     => $summary,
            ':details'     => $details,
            ':id'          => $id,
        ]);
    }
}
