<?php
require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../includes/session_security.php';
require_once __DIR__ . '/../../../Core/Database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$callerId = (int) $_SESSION['user_id'];
$receiverId = (int) ($_POST['receiver_id'] ?? 0);
$roomToken = trim((string) ($_POST['room_token'] ?? ''));

if ($receiverId < 1 || $callerId === $receiverId || $roomToken === '') {
    echo json_encode(['success' => false, 'error' => 'Missing data']);
    exit;
}

$db = \Slayly\Core\Database::getInstance()->getConnection();

// Serialize per-receiver so two parallel initiations cannot reject each other's row mid-flight.
$lockName = 'slaymeet_initcall_' . $receiverId;
$glStmt = $db->prepare('SELECT GET_LOCK(?, 10) AS got');
$glStmt->execute([$lockName]);
$gotLock = (int) ($glStmt->fetchColumn() ?? 0) === 1;
if (!$gotLock) {
    echo json_encode(['success' => false, 'error' => 'Could not start call — try again']);
    exit;
}

try {
    $db->beginTransaction();

    // Same caller re-dialing same receiver: refresh the existing ringing row (do not insert a second one).
    $find = $db->prepare(
        "SELECT id FROM slaymeet_calls
         WHERE caller_id = ? AND receiver_id = ? AND status = 'ringing'
         LIMIT 1 FOR UPDATE"
    );
    $find->execute([$callerId, $receiverId]);
    $existingId = $find->fetchColumn();

    if ($existingId) {
        $up = $db->prepare('UPDATE slaymeet_calls SET room_token = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $up->execute([$roomToken, (int) $existingId]);
        $callId = (int) $existingId;
    } else {
        // New ring: clear anyone else's ringing to this receiver, then insert.
        $rej = $db->prepare("UPDATE slaymeet_calls SET status = 'rejected' WHERE receiver_id = ? AND status = 'ringing'");
        $rej->execute([$receiverId]);
        $ins = $db->prepare(
            "INSERT INTO slaymeet_calls (caller_id, receiver_id, room_token, status) VALUES (?, ?, ?, 'ringing')"
        );
        $ins->execute([$callerId, $receiverId, $roomToken]);
        $callId = (int) $db->lastInsertId();
    }

    $db->commit();
    echo json_encode(['success' => true, 'call_id' => $callId]);
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'error' => 'DB error']);
} finally {
    $rl = $db->prepare('SELECT RELEASE_LOCK(?)');
    $rl->execute([$lockName]);
}
