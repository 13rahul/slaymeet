<?php
require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../includes/session_security.php';
require_once __DIR__ . '/../../Domain/slaymeet_calls_schema.php';
require_once __DIR__ . '/../../../Core/Database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$callId = (int) ($_POST['call_id'] ?? 0);
$status = strtolower(trim((string) ($_POST['status'] ?? 'accepted')));
if (!in_array($status, ['accepted', 'rejected'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid status']);
    exit;
}

try {
    $db = \Slayly\Core\Database::getInstance()->getConnection();
    slayly_ensure_slaymeet_calls_table($db);
    $receiverId = (int) $_SESSION['user_id'];
    if ($status === 'accepted') {
        $stmt = $db->prepare("UPDATE slaymeet_calls SET status = ?, started_at = COALESCE(started_at, NOW()) WHERE id = ? AND receiver_id = ?");
    } else {
        $stmt = $db->prepare("UPDATE slaymeet_calls SET status = ?, ended_at = COALESCE(ended_at, NOW()) WHERE id = ? AND receiver_id = ?");
    }
    $stmt->execute([$status, $callId, $receiverId]);
    if ($stmt->rowCount() < 1) {
        echo json_encode(['success' => false, 'error' => 'Call not found or already handled']);
        exit;
    }
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'DB error']);
}
