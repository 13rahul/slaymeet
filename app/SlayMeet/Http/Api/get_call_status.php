<?php
/**
 * Lightweight status read for ringing verification (caller cancelled / callee answered elsewhere).
 */
require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../includes/session_security.php';
require_once __DIR__ . '/../../../Core/Database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
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
    $stmt = $db->prepare(
        'SELECT status FROM slaymeet_calls WHERE id = ? AND (caller_id = ? OR receiver_id = ?) LIMIT 1'
    );
    $stmt->execute([$callId, $uid, $uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'not_found']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'status' => (string) ($row['status'] ?? ''),
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'DB error']);
}
