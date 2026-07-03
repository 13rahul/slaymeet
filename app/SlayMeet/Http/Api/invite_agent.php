<?php
require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../includes/SlayGuard.php';
require_once __DIR__ . '/../../Domain/SlayMeetAgent.php';
require_once __DIR__ . '/../../Domain/slaymeet_helpers.php';

// Gatekeep with strict POST, CSRF, and rate limiting
$guard = SlayGuard::gatekeep([
    'rate_limit' => 'slaymeet_write',
    'csrf' => true,
    'post_only' => true,
    'company' => false
]);

header('Content-Type: application/json');

$jsonFlags = JSON_UNESCAPED_UNICODE;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
}

$userId = (int) $guard['user_id'];
$companyId = (int) $guard['company_id'];
$roomToken = trim((string) ($_POST['room'] ?? ''));

if ($roomToken === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Room token required'], $jsonFlags);
    exit;
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed'], $jsonFlags);
    exit;
}

// Fetch the room details
$stmt = $conn->prepare("
    SELECT id, company_id, room_name, status, created_by
    FROM slay_meet_rooms
    WHERE public_token = ?
    LIMIT 1
");
$stmt->bind_param('s', $roomToken);
$stmt->execute();
$room = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$room) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Room not found'], $jsonFlags);
    $conn->close();
    exit;
}

if ($room['status'] !== 'live') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Room is not live'], $jsonFlags);
    $conn->close();
    exit;
}

$botSession = SlayMeetAgent::issueBotToken(
    (int) $room['id'],
    (int) $room['company_id'],
    $userId,
    $roomToken
);

$lobbyReg = SlayMeetHelpers::registerBotInWaitingLobby(
    $conn,
    (int) $room['id'],
    (int) $room['company_id']
);

$conn->close();

$rootDir = dirname(__DIR__, 4);
SlayMeetHelpers::appendBotInviteLog(
    $rootDir,
    'room=' . $roomToken
    . ' lobby_registered=' . ($lobbyReg['ok'] ? '1' : '0')
    . ($lobbyReg['ok'] ? '' : ' warn=' . ($lobbyReg['message'] ?? ''))
);
$botUrl = SlayMeetHelpers::meetingPageUrl($roomToken, [
    'bot' => '1',
    'iframe' => '1',
    'bot_token' => $botSession['token'],
    'company_id' => $companyId,
    'user_id' => $userId,
    'room_id' => (int) $room['id'],
]);

// Background headless join only (no extra visible Chrome window for the host).
$spawn = SlayMeetHelpers::spawnMeetingBot($botUrl, $rootDir);
if ($spawn['mode'] === 'missing_script') {
    SlayMeetHelpers::appendBotInviteLog(
        $rootDir,
        'room=' . $roomToken . ' spawn=skipped (in-page host agent; optional headless runner not installed)'
    );
}

$guestInviteLink = SlayMeetHelpers::meetingPageUrl($roomToken);

$inviteMessage = $lobbyReg['ok']
    ? 'Ultra Looper AI Assistant is in the waiting room — open Admit guests and click Admit.'
    : 'The AI assistant could not join the waiting lobby. ' . ($lobbyReg['message'] ?? '');

$payload = [
    'success' => true,
    'message' => $inviteMessage,
    'lobby_registered' => !empty($lobbyReg['ok']),
    'lobby_warning' => empty($lobbyReg['ok']) ? ($lobbyReg['message'] ?? '') : '',
    'launch_mode' => 'iframe',
    'guest_invite_link' => $guestInviteLink,
    'bot_join_url' => $botUrl,
    'bot_token' => $botSession['token'],
    'bot_user_id' => (int) ($lobbyReg['user_id'] ?? 0),
    'room_id' => (int) $room['id'],
    'web_base' => SlayMeetHelpers::requestBaseUrl(),
    'bot_log' => 'storage/logs/slaymeet-bot-' . date('Y-m-d') . '.log',
    'spawn_ok' => $spawn['ok'],
];
if (defined('DEBUG_MODE') && DEBUG_MODE) {
    $payload['debug'] = [
        'site_url_constant' => defined('SITE_URL') ? SITE_URL : null,
        'script_name' => $_SERVER['SCRIPT_NAME'] ?? null,
    ];
}
echo json_encode($payload, $jsonFlags);
exit;
