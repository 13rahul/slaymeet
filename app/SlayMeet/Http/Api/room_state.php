<?php
require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../includes/session_security.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$companyId = (int) ($_SESSION['company_id'] ?? 0);
$roomToken = trim((string) ($_GET['room'] ?? ''));
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

$userId = (int) $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT id, room_name, status, starts_at, ended_at, created_by FROM slay_meet_rooms WHERE public_token = ? LIMIT 1");
$stmt->bind_param('s', $roomToken);
$stmt->execute();
$room = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$room) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Room not found']);
    exit;
}

$participants = [];
$roomId = (int) $room['id'];
$hasAdmission = false;
$hasRequested = false;
$check = $conn->query("SHOW COLUMNS FROM slay_meet_participants");
if ($check) {
    while ($c = $check->fetch_assoc()) {
        $f = (string) ($c['Field'] ?? '');
        if ($f === 'admission_status') $hasAdmission = true;
        if ($f === 'requested_at') $hasRequested = true;
    }
}
$waitingRoomReady = $hasAdmission && $hasRequested;
$participantsSql = $waitingRoomReady
    ? "SELECT p.user_id, p.role, p.joined_at, u.name, u.profile_pic
       FROM slay_meet_participants p
       LEFT JOIN users u ON u.id = p.user_id
       WHERE p.room_id = ? AND p.is_active = 1 AND p.admission_status = 'admitted'
       ORDER BY p.joined_at ASC"
    : "SELECT p.user_id, p.role, p.joined_at, u.name, u.profile_pic
       FROM slay_meet_participants p
       LEFT JOIN users u ON u.id = p.user_id
       WHERE p.room_id = ? AND p.is_active = 1
       ORDER BY p.joined_at ASC";
$stmt = $conn->prepare($participantsSql);
$stmt->bind_param('i', $roomId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    if ((int)$row['user_id'] === -1) {
        $row['name'] = 'Ultra Looper AI Assistant';
        $row['profile_pic'] = '';
    }
    $participants[] = $row;
}
$stmt->close();

$pendingRequests = [];
if ($waitingRoomReady && (int) ($room['created_by'] ?? 0) === $userId) {
    $stmt = $conn->prepare("
        SELECT p.user_id, p.role, p.requested_at, u.name, u.profile_pic
        FROM slay_meet_participants p
        LEFT JOIN users u ON u.id = p.user_id
        WHERE p.room_id = ?
          AND p.admission_status = 'pending'
          AND p.is_active = 0
        ORDER BY p.requested_at ASC
    ");
    $stmt->bind_param('i', $roomId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        if ((int)$row['user_id'] === -1) {
            $row['name'] = 'Ultra Looper AI Assistant';
            $row['profile_pic'] = '';
        }
        $pendingRequests[] = $row;
    }
    $stmt->close();
}
$conn->close();

echo json_encode([
    'success' => true,
    'room' => $room,
    'participants' => $participants,
    'pending_requests' => $pendingRequests
]);
?>
