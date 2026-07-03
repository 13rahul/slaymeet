<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../Domain/SlayMeetAgent.php';
require_once __DIR__ . '/../../Infrastructure/Speech/SlayMeetSpeech.php';

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
$text = trim((string) ($input['text'] ?? ''));
$voice = trim((string) ($input['voice'] ?? ''));

if ($botToken === '' || $text === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'bot_token and text required'], $jsonFlags);
    exit;
}

$claims = SlayMeetAgent::validateBotToken($botToken);
if ($claims === null) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid bot session'], $jsonFlags);
    exit;
}

try {
    $result = SlayMeetSpeech::synthesize($text, $voice);
    echo json_encode([
        'success' => true,
        'audio_base64' => base64_encode($result['wav']),
        'format' => 'wav',
        'sample_rate' => $result['sample_rate'],
        'voice' => $result['voice'],
        'engine' => $result['engine'],
        'model' => $result['model'],
    ], $jsonFlags);
} catch (Throwable $e) {
    error_log('[agent_tts] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], $jsonFlags);
}
