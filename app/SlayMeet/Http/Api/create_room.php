<?php
require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../includes/SlayGuard.php';

$guard = SlayGuard::gatekeep([
    'rate_limit' => 'slaymeet_write',
    'csrf' => true,
    'post_only' => true
]);

header('Content-Type: application/json');

$userId = (int) $guard['user_id'];
$companyId = (int) $guard['company_id'];
$roomName = trim((string) ($_POST['room_name'] ?? 'Instant Meeting'));

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$publicToken = bin2hex(random_bytes(16));
$hostToken = bin2hex(random_bytes(24));
$status = 'live';
$startsAt = date('Y-m-d H:i:s');

// mysqli bind_param type "i" + NULL for channel_id is unreliable across PHP versions; use explicit SQL.
$channelId = isset($_POST['channel_id']) ? (int) $_POST['channel_id'] : 0;
$channelId = $channelId > 0 ? $channelId : null;

if ($channelId === null) {
    $stmt = $conn->prepare("
        INSERT INTO slay_meet_rooms
        (company_id, channel_id, created_by, room_name, public_token, host_token, status, starts_at)
        VALUES (?, NULL, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to prepare room insert', 'detail' => DEBUG_MODE ? $conn->error : null]);
        exit;
    }
    $stmt->bind_param('iisssss', $companyId, $userId, $roomName, $publicToken, $hostToken, $status, $startsAt);
} else {
    $stmt = $conn->prepare("
        INSERT INTO slay_meet_rooms
        (company_id, channel_id, created_by, room_name, public_token, host_token, status, starts_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to prepare room insert', 'detail' => DEBUG_MODE ? $conn->error : null]);
        exit;
    }
    $stmt->bind_param('iiisssss', $companyId, $channelId, $userId, $roomName, $publicToken, $hostToken, $status, $startsAt);
}

if (!$stmt->execute()) {
    http_response_code(500);
    $msg = 'Failed to create room';
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        $msg .= ': ' . $stmt->error;
    }
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}
$roomId = (int) $stmt->insert_id;
$stmt->close();

$role = 'host';
$stmt = $conn->prepare("INSERT INTO slay_meet_participants (room_id, user_id, role, joined_at, is_active) VALUES (?, ?, ?, NOW(), 1)");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare participant insert']);
    exit;
}
$stmt->bind_param('iis', $roomId, $userId, $role);
if (!$stmt->execute()) {
    http_response_code(500);
    $msg = 'Failed to add host to room';
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        $msg .= ': ' . $stmt->error;
    }
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}
$stmt->close();
$conn->close();

echo json_encode([
    'success' => true,
    'room_id' => $roomId,
    'room_token' => $publicToken,
    'host_token' => $hostToken
]);
?>
