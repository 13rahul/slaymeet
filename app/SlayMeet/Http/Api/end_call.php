<?php
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

$roomToken = trim((string) ($_POST['room_token'] ?? $_POST['room'] ?? ''));
$finalStatus = strtolower(trim((string) ($_POST['status'] ?? 'ended')));
if (!in_array($finalStatus, ['ended', 'rejected'], true)) {
    $finalStatus = 'ended';
}

if ($roomToken === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'room_token required']);
    exit;
}

$userId = (int) $_SESSION['user_id'];

try {
    $db = \Slayly\Core\Database::getInstance()->getConnection();
    slayly_finalize_slaymeet_call($db, $roomToken, $userId, $finalStatus);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'DB error']);
}
