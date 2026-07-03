<?php
require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../includes/SlayGuard.php';
require_once __DIR__ . '/../../Domain/slaymeet_helpers.php';

$guard = SlayGuard::gatekeep([
    'rate_limit' => 'slaymeet_write',
    'csrf' => true,
    'post_only' => true,
    'company' => false
]);

header('Content-Type: application/json');

$userId = (int) $guard['user_id'];
$companyId = (int) $guard['company_id'];
$roomToken = trim((string) ($_POST['room'] ?? ''));
$signalType = trim((string) ($_POST['signal_type'] ?? ''));
$payload = trim((string) ($_POST['payload'] ?? ''));
$toUserId = isset($_POST['to_user_id']) ? (int) $_POST['to_user_id'] : null;

if ($roomToken === '' || $signalType === '' || $payload === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$allowed = ['offer', 'answer', 'ice', 'system'];
if (!in_array($signalType, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid signal type']);
    exit;
}

$decoded = json_decode($payload, true);
if (!is_array($decoded)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid payload JSON']);
    exit;
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

SlayMeetHelpers::ensureSignalingSchema($conn);

$stmt = $conn->prepare("
    SELECT r.id AS room_id
    FROM slay_meet_rooms r
    JOIN slay_meet_participants p ON p.room_id = r.id AND p.user_id = ? AND p.is_active = 1
    WHERE r.public_token = ?
    LIMIT 1
");
$stmt->bind_param('is', $userId, $roomToken);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized for this room']);
    exit;
}
$roomId = (int) $row['room_id'];

if ($toUserId !== null && $toUserId > 0) {
    $stmt = $conn->prepare("SELECT 1 FROM slay_meet_participants WHERE room_id = ? AND user_id = ? LIMIT 1");
    $stmt->bind_param('ii', $roomId, $toUserId);
    $stmt->execute();
    $ok = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$ok) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Recipient not in room']);
        exit;
    }
} else {
    $toUserId = null;
}

/*
 * Broadcast rows must store to_user_id = NULL (room-wide). mysqli bind_param('i', null)
 * often coerces NULL to 0, which breaks signal_poll (matches nobody). Use a NULL literal insert.
 */
if ($toUserId !== null && $toUserId > 0) {
    $stmt = $conn->prepare('
        INSERT INTO slay_meet_signals (room_id, from_user_id, to_user_id, signal_type, payload_json)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->bind_param('iiiss', $roomId, $userId, $toUserId, $signalType, $payload);
} else {
    $stmt = $conn->prepare('
        INSERT INTO slay_meet_signals (room_id, from_user_id, to_user_id, signal_type, payload_json)
        VALUES (?, ?, NULL, ?, ?)
    ');
    $stmt->bind_param('iiss', $roomId, $userId, $signalType, $payload);
}
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to store signal']);
    exit;
}
$signalId = (int) $stmt->insert_id;
$stmt->close();
$conn->close();

echo json_encode(['success' => true, 'signal_id' => $signalId]);
?>
