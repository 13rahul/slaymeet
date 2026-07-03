<?php
require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../includes/SlayGuard.php';
require_once __DIR__ . '/../../Domain/slaymeet_helpers.php';

$guard = SlayGuard::gatekeep([
    'rate_limit' => 'slaymeet_write',
    'csrf' => true,
    'post_only' => true
]);

header('Content-Type: application/json');

$userId = (int) $guard['user_id'];
$companyId = (int) $guard['company_id'];
$roomToken = trim((string) ($_POST['room'] ?? ''));
if ($roomToken === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'room token required']);
    exit;
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$stmt = $conn->prepare("SELECT id FROM slay_meet_rooms WHERE company_id = ? AND public_token = ? LIMIT 1");
$stmt->bind_param('is', $companyId, $roomToken);
$stmt->execute();
$room = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$room) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Room not found']);
    exit;
}
$roomId = (int) $room['id'];

$stmt = $conn->prepare("UPDATE slay_meet_participants SET is_active = 0, left_at = NOW() WHERE room_id = ? AND user_id = ?");
$stmt->bind_param('ii', $roomId, $userId);
$stmt->execute();
$stmt->close();

SlayMeetHelpers::clearStaleAiParticipants($conn, $roomToken);

$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM slay_meet_participants WHERE room_id = ? AND is_active = 1");
$stmt->bind_param('i', $roomId);
$stmt->execute();
$countRes = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ((int) ($countRes['c'] ?? 0) === 0) {
    $status = 'ended';
    $stmt = $conn->prepare("UPDATE slay_meet_rooms SET status = ?, ended_at = NOW() WHERE id = ?");
    $stmt->bind_param('si', $status, $roomId);
    $stmt->execute();
    $stmt->close();
    SlayMeetHelpers::pruneRoomSignals($conn, $roomId);
}

$conn->close();
echo json_encode(['success' => true]);
?>
