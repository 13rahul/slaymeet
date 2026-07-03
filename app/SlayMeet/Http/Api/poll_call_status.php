<?php
require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../includes/session_security.php';
require_once __DIR__ . '/../../../Core/Database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$callId = (int) ($_GET['call_id'] ?? 0);
if ($callId < 1) {
    echo json_encode(['success' => false, 'error' => 'bad_id']);
    exit;
}

$uid = (int) $_SESSION['user_id'];

try {
    $db = \Slayly\Core\Database::getInstance()->getConnection();
    $stmt = $db->prepare('SELECT status, caller_id FROM slaymeet_calls WHERE id = ?');
    $stmt->execute([$callId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'not_found']);
        exit;
    }

    if ((int) $row['caller_id'] !== $uid) {
        echo json_encode(['success' => false, 'error' => 'forbidden']);
        exit;
    }

    $status = (string) ($row['status'] ?? '');
    echo json_encode(['success' => true, 'status' => $status]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'DB error']);
}
