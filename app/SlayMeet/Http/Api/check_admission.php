<?php
require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../includes/SlayGuard.php';

$guard = SlayGuard::gatekeep([
    'rate_limit' => 'slaymeet_read',
    'csrf' => false,
    'company' => false
]);

header('Content-Type: application/json');

$userId = (int) $guard['user_id'];
$companyId = (int) $guard['company_id'];
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

$stmt = $conn->prepare("SELECT id, created_by FROM slay_meet_rooms WHERE public_token = ? LIMIT 1");
$stmt->bind_param('s', $roomToken);
$stmt->execute();
$room = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$room) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Room not found']);
    exit;
}
$roomId = (int) $room['id'];

$hasAdmission = false;
$check = $conn->query("SHOW COLUMNS FROM slay_meet_participants LIKE 'admission_status'");
if ($check && $check->num_rows > 0) $hasAdmission = true;
if (!$hasAdmission) {
    echo json_encode([
        'success' => true,
        'admission_status' => 'admitted',
        'admitted' => true,
        'role' => 'participant'
    ]);
    exit;
}

$stmt = $conn->prepare("
    SELECT admission_status, is_active, role
    FROM slay_meet_participants
    WHERE room_id = ? AND user_id = ?
    LIMIT 1
");
$stmt->bind_param('ii', $roomId, $userId);
$stmt->execute();
$participant = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$participant) {
    echo json_encode([
        'success' => true,
        'admission_status' => 'none',
        'admitted' => false
    ]);
    exit;
}

$status = (string) ($participant['admission_status'] ?? 'admitted');
echo json_encode([
    'success' => true,
    'admission_status' => $status,
    // Status is the source of truth; join_room will normalize active state on next join.
    'admitted' => ($status === 'admitted'),
    'role' => (string) ($participant['role'] ?? 'participant')
]);
