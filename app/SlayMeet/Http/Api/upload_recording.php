<?php
/**
 * API: SlayMeet recording upload — saves to storage/recordings/
 */

require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../includes/SlayGuard.php';

header('Content-Type: application/json; charset=utf-8');

$guard = SlayGuard::gatekeep([
    'rate_limit' => 'slaymeet_upload',
    'csrf' => true,
    'post_only' => true,
]);

$userId = (int) $guard['user_id'];
$companyId = (int) $guard['company_id'];

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}
$conn->set_charset('utf8mb4');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $roomToken = trim((string) ($_POST['room'] ?? ''));
    if ($roomToken === '') {
        throw new Exception('Room token required');
    }

    $stmtRoom = $conn->prepare('
        SELECT id, company_id, created_by, room_name
        FROM slay_meet_rooms
        WHERE public_token = ?
        LIMIT 1
    ');
    $stmtRoom->bind_param('s', $roomToken);
    $stmtRoom->execute();
    $roomRow = $stmtRoom->get_result()->fetch_assoc();
    $stmtRoom->close();

    if (!$roomRow || (int) ($roomRow['company_id'] ?? 0) !== $companyId) {
        throw new Exception('Room not found or not in your company');
    }

    $hostUserId = (int) ($roomRow['created_by'] ?? 0);
    if ($userId !== $hostUserId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only the meeting host can save recordings.']);
        $conn->close();
        exit;
    }

    if (!isset($_FILES['video_blob']) || $_FILES['video_blob']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No video data received');
    }

    $roomName = (string) ($_POST['room_name'] ?? ($roomRow['room_name'] ?? 'Untitled Meeting'));
    $recordingId = $_POST['recording_id'] ?? bin2hex(random_bytes(8));

    $uploadDir = dirname(__DIR__, 4) . '/storage/recordings';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $filename = 'meet_' . $recordingId . '_' . time() . '.webm';
    $targetPath = $uploadDir . '/' . $filename;
    $relativeUrl = 'storage/recordings/' . $filename;

    if (!move_uploaded_file($_FILES['video_blob']['tmp_name'], $targetPath)) {
        throw new Exception('Failed to save recording file');
    }

    echo json_encode([
        'success' => true,
        'message' => 'Recording saved to storage/recordings',
        'url' => $relativeUrl,
        'title' => $roomName . ' — ' . date('d M Y H:i'),
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
