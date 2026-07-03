<?php
/**
 * Caller hangs up before the callee answers — marks row rejected so ringing stops everywhere.
 */
require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../includes/session_security.php';
require_once __DIR__ . '/../../Domain/slaymeet_calls_schema.php';
require_once __DIR__ . '/../../../Core/Database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$callId = (int) ($_POST['call_id'] ?? 0);
if ($callId < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'bad_id']);
    exit;
}

$callerId = (int) $_SESSION['user_id'];

try {
    $db = \Slayly\Core\Database::getInstance()->getConnection();
    slayly_ensure_slaymeet_calls_table($db);
    $stmt = $db->prepare(
        "UPDATE slaymeet_calls SET status = 'rejected', ended_at = COALESCE(ended_at, NOW())
         WHERE id = ? AND caller_id = ? AND status = 'ringing'"
    );
    $stmt->execute([$callId, $callerId]);

    if ($stmt->rowCount() < 1) {
        echo json_encode(['success' => false, 'error' => 'Call not found or already answered']);
        exit;
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'DB error']);
}
