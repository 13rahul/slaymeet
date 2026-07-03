<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../Domain/SlayMeetAgent.php';

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
$message = trim((string) ($input['message'] ?? ''));
$context = trim((string) ($input['meeting_context'] ?? ''));
$history = $input['history'] ?? [];

if ($botToken === '' || $message === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'bot_token and message required'], $jsonFlags);
    exit;
}

if (mb_strlen($message) > 4000) {
    $message = mb_substr($message, 0, 4000);
}

$claims = SlayMeetAgent::validateBotToken($botToken);
if ($claims === null) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired bot session'], $jsonFlags);
    exit;
}

$normalizedHistory = [];
if (is_array($history)) {
    foreach (array_slice($history, -20) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $normalizedHistory[] = [
            'role' => ($row['role'] ?? '') === 'model' ? 'model' : 'user',
            'text' => mb_substr(trim((string) ($row['text'] ?? '')), 0, 2000),
        ];
    }
}

$reply = SlayMeetAgent::respond(
    $normalizedHistory,
    $message,
    $context,
    (int) ($claims['company_id'] ?? 0),
    (int) ($claims['inviter_user_id'] ?? 0)
);

echo json_encode([
    'success' => true,
    'reply' => $reply,
    'room_id' => $claims['room_id'],
], $jsonFlags);
