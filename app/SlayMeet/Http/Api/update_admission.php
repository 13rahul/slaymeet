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
$roomToken = trim((string) ($_POST['room'] ?? ''));
$participantUserId = (int) ($_POST['participant_user_id'] ?? 0);
$action = trim(strtolower((string) ($_POST['action'] ?? '')));

if ($roomToken === '' || $participantUserId <= 0 || !in_array($action, ['admit', 'deny'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid payload']);
    exit;
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$stmt = $conn->prepare("SELECT id, created_by, company_id FROM slay_meet_rooms WHERE public_token = ? LIMIT 1");
$stmt->bind_param('s', $roomToken);
$stmt->execute();
$room = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$room) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Room not found']);
    exit;
}

if ((int) ($room['created_by'] ?? 0) !== $userId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only host can manage admission']);
    exit;
}

$roomId = (int) $room['id'];
$hasAdmission = false;
$hasDecidedAt = false;
$hasDecidedBy = false;
$check = $conn->query("SHOW COLUMNS FROM slay_meet_participants");
if ($check) {
    while ($c = $check->fetch_assoc()) {
        $f = (string) ($c['Field'] ?? '');
        if ($f === 'admission_status') $hasAdmission = true;
        if ($f === 'decided_at') $hasDecidedAt = true;
        if ($f === 'decided_by') $hasDecidedBy = true;
    }
}
if (!($hasAdmission && $hasDecidedAt && $hasDecidedBy)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Waiting room is not enabled on this database. Run: php database/migrations/007_slaymeet_waiting_room.php',
        'migration_required' => '007_slaymeet_waiting_room',
    ]);
    exit;
}

$stmt = $conn->prepare("
    SELECT admission_status
    FROM slay_meet_participants
    WHERE room_id = ? AND user_id = ?
    LIMIT 1
");
$stmt->bind_param('ii', $roomId, $participantUserId);
$stmt->execute();
$participant = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$participant) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Guest not found']);
    exit;
}

if ($action === 'admit') {
    $status = 'admitted';
    $isActive = 1;
    $stmt = $conn->prepare("
        UPDATE slay_meet_participants
        SET admission_status = ?,
            is_active = ?,
            decided_by = ?,
            decided_at = NOW(),
            joined_at = NOW(),
            left_at = NULL
        WHERE room_id = ? AND user_id = ?
    ");
    $stmt->bind_param('siiii', $status, $isActive, $userId, $roomId, $participantUserId);
    $stmt->execute();
    $stmt->close();
} else {
    $status = 'denied';
    $isActive = 0;
    $stmt = $conn->prepare("
        UPDATE slay_meet_participants
        SET admission_status = ?,
            is_active = ?,
            decided_by = ?,
            decided_at = NOW(),
            left_at = NOW()
        WHERE room_id = ? AND user_id = ?
    ");
    $stmt->bind_param('siiii', $status, $isActive, $userId, $roomId, $participantUserId);
    $stmt->execute();
    $stmt->close();
}

$conn->close();
echo json_encode([
    'success' => true,
    'participant_user_id' => $participantUserId,
    'admission_status' => $status
]);
