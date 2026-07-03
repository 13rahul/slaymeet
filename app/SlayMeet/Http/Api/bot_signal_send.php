<?php
declare(strict_types=1);

/**
 * Post meeting signals as Slayly AI using a signed bot_token (no bot iframe session required).
 */
require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../Domain/SlayMeetAgent.php';
require_once __DIR__ . '/../../Domain/slaymeet_helpers.php';

header('Content-Type: application/json; charset=utf-8');

$jsonFlags = JSON_UNESCAPED_UNICODE;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required'], $jsonFlags);
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($input)) {
    $input = $_POST;
}

$botToken = trim((string) ($input['bot_token'] ?? ''));
$roomToken = trim((string) ($input['room'] ?? ''));
$signalType = trim((string) ($input['signal_type'] ?? 'system'));
$payloadRaw = $input['payload'] ?? '';
$toUserId = isset($input['to_user_id']) ? (int) $input['to_user_id'] : 0;

if ($botToken === '' || $roomToken === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'bot_token and room required'], $jsonFlags);
    exit;
}

$allowed = ['offer', 'answer', 'ice', 'system'];
if (!in_array($signalType, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid signal type'], $jsonFlags);
    exit;
}

if (is_array($payloadRaw)) {
    $payload = json_encode($payloadRaw, JSON_UNESCAPED_UNICODE);
} else {
    $payload = trim((string) $payloadRaw);
}
if ($payload === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'payload required'], $jsonFlags);
    exit;
}

$decoded = json_decode($payload, true);
if (!is_array($decoded)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid payload JSON'], $jsonFlags);
    exit;
}

$claims = SlayMeetAgent::validateBotToken($botToken);
if ($claims === null || ($claims['room_token'] ?? '') !== $roomToken) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired bot token'], $jsonFlags);
    exit;
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed'], $jsonFlags);
    exit;
}

$roomId = (int) ($claims['room_id'] ?? 0);
$companyId = (int) ($claims['company_id'] ?? 0);
$botUserId = SlayMeetHelpers::resolveSlaylyAiGuestUserId($conn, $companyId);
if ($botUserId <= 0) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Slayly AI user not found'], $jsonFlags);
    $conn->close();
    exit;
}

$stmt = $conn->prepare('SELECT id FROM slay_meet_rooms WHERE public_token = ? AND id = ? LIMIT 1');
$stmt->bind_param('si', $roomToken, $roomId);
$stmt->execute();
$roomOk = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$roomOk) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Room not found'], $jsonFlags);
    $conn->close();
    exit;
}

$partSql = SlayMeetHelpers::waitingRoomSchemaReady($conn)
    ? "SELECT 1 FROM slay_meet_participants WHERE room_id = ? AND user_id = ? AND admission_status = 'admitted' AND is_active = 1 LIMIT 1"
    : "SELECT 1 FROM slay_meet_participants WHERE room_id = ? AND user_id = ? AND is_active = 1 LIMIT 1";
$stmt = $conn->prepare($partSql);
$stmt->bind_param('ii', $roomId, $botUserId);
$stmt->execute();
$active = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$active) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Slayly AI is not an active participant in this room'], $jsonFlags);
    $conn->close();
    exit;
}

if ($toUserId > 0) {
    $stmt = $conn->prepare('SELECT 1 FROM slay_meet_participants WHERE room_id = ? AND user_id = ? LIMIT 1');
    $stmt->bind_param('ii', $roomId, $toUserId);
    $stmt->execute();
    $ok = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$ok) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Recipient not in room'], $jsonFlags);
        $conn->close();
        exit;
    }
    $stmt = $conn->prepare('
        INSERT INTO slay_meet_signals (room_id, from_user_id, to_user_id, signal_type, payload_json)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->bind_param('iiiss', $roomId, $botUserId, $toUserId, $signalType, $payload);
} else {
    $stmt = $conn->prepare('
        INSERT INTO slay_meet_signals (room_id, from_user_id, to_user_id, signal_type, payload_json)
        VALUES (?, ?, NULL, ?, ?)
    ');
    $stmt->bind_param('iiss', $roomId, $botUserId, $signalType, $payload);
}

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to store signal'], $jsonFlags);
    $stmt->close();
    $conn->close();
    exit;
}

$signalId = (int) $stmt->insert_id;
$stmt->close();
$conn->close();

echo json_encode([
    'success' => true,
    'signal_id' => $signalId,
    'from_user_id' => $botUserId,
], $jsonFlags);
