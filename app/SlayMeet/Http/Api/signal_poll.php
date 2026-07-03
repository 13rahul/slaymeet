<?php
require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../includes/SlayGuard.php';
require_once __DIR__ . '/../../Domain/slaymeet_helpers.php';

$guard = SlayGuard::gatekeep([
    'rate_limit' => 'slaymeet_read',
    'csrf' => false,
    'company' => false
]);

header('Content-Type: application/json');

$userId = (int) $guard['user_id'];
$roomToken = trim((string) ($_GET['room'] ?? ''));
$sinceId = isset($_GET['since_id']) ? (int) $_GET['since_id'] : 0;
$longPoll = isset($_GET['long_poll']) && (string) $_GET['long_poll'] === '1';

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

SlayMeetHelpers::ensureSignalingSchema($conn);

if (function_exists('session_write_close')) {
    session_write_close();
}

$deadline = $longPoll ? microtime(true) + 18.0 : microtime(true);
$batch = null;

do {
    $batch = SlayMeetHelpers::pollMemberSignals($conn, $userId, $roomToken, $sinceId);
    if ($batch === null) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not authorized for this room']);
        $conn->close();
        exit;
    }
    if (!empty($batch['signals']) || !$longPoll) {
        break;
    }
    usleep(120000);
} while (microtime(true) < $deadline);

$conn->close();

echo json_encode([
    'success' => true,
    'signals' => $batch['signals'],
    'last_id' => $batch['last_id'],
]);
