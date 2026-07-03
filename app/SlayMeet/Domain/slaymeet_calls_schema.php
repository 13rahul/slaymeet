<?php
/**
 * slaymeet_calls schema helpers — live rings + call history columns.
 */

require_once __DIR__ . '/../../includes/schema_util.php';

function slayly_ensure_slaymeet_calls_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS slaymeet_calls (
        id INT AUTO_INCREMENT PRIMARY KEY,
        caller_id INT NOT NULL,
        receiver_id INT NOT NULL,
        room_token VARCHAR(255) NOT NULL,
        status ENUM('ringing', 'accepted', 'rejected', 'ended') DEFAULT 'ringing',
        started_at DATETIME DEFAULT NULL,
        ended_at DATETIME DEFAULT NULL,
        duration_sec INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (receiver_id),
        INDEX (caller_id),
        INDEX (room_token)
    )");

    $columns = [
        'started_at' => 'ALTER TABLE slaymeet_calls ADD COLUMN started_at DATETIME DEFAULT NULL AFTER status',
        'ended_at' => 'ALTER TABLE slaymeet_calls ADD COLUMN ended_at DATETIME DEFAULT NULL AFTER started_at',
        'duration_sec' => 'ALTER TABLE slaymeet_calls ADD COLUMN duration_sec INT DEFAULT NULL AFTER ended_at',
    ];
    foreach ($columns as $name => $sql) {
        try {
            if (!slayly_db_column_exists($pdo, 'slaymeet_calls', $name)) {
                $pdo->exec($sql);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
}

function slayly_finalize_slaymeet_call(PDO $pdo, string $roomToken, int $userId, string $finalStatus = 'ended'): void
{
    slayly_ensure_slaymeet_calls_table($pdo);
    $stmt = $pdo->prepare("
        SELECT id, status, started_at
        FROM slaymeet_calls
        WHERE room_token = ?
          AND (caller_id = ? OR receiver_id = ?)
          AND status IN ('ringing', 'accepted')
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$roomToken, $userId, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }

    $callId = (int) $row['id'];
    $startedAt = $row['started_at'] ?? null;
    $duration = null;
    if ($startedAt) {
        $startTs = strtotime((string) $startedAt);
        if ($startTs !== false) {
            $duration = max(0, time() - $startTs);
        }
    }

    $up = $pdo->prepare("
        UPDATE slaymeet_calls
        SET status = ?,
            ended_at = NOW(),
            duration_sec = COALESCE(?, duration_sec)
        WHERE id = ?
    ");
    $up->execute([$finalStatus, $duration, $callId]);
}
