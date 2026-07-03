<?php
declare(strict_types=1);

/**
 * Server-Sent Events stream for instant WebRTC signaling (replaces slow client polling).
 */
require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../includes/SlayGuard.php';
require_once __DIR__ . '/../../Domain/slaymeet_helpers.php';

$guard = SlayGuard::gatekeep([
    'rate_limit' => 'slaymeet_read',
    'csrf' => false,
    'company' => false,
]);

$userId = (int) $guard['user_id'];
$roomToken = trim((string) ($_GET['room'] ?? ''));
$sinceId = isset($_GET['since_id']) ? (int) $_GET['since_id'] : 0;

if ($roomToken === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'room token required';
    exit;
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'database error';
    exit;
}

$roomId = SlayMeetHelpers::resolveActiveRoomMember($conn, $userId, $roomToken);
if ($roomId === null) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'not authorized';
    $conn->close();
    exit;
}

if (function_exists('session_write_close')) {
    session_write_close();
}

@set_time_limit(0);
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    ob_end_flush();
}

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

echo "event: ready\ndata: " . json_encode(['success' => true, 'room_id' => $roomId]) . "\n\n";
flush();

$cursor = max(0, $sinceId);
$started = time();
$maxSeconds = 50;

while (!connection_aborted() && (time() - $started) < $maxSeconds) {
    $batch = SlayMeetHelpers::fetchSignalsForMember($conn, $roomId, $userId, $cursor, 250);
    if (!empty($batch['signals'])) {
        $cursor = (int) $batch['last_id'];
        echo 'data: ' . json_encode([
            'success' => true,
            'signals' => $batch['signals'],
            'last_id' => $cursor,
        ], JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
        usleep(25000);
        continue;
    }

    echo ": ping " . time() . "\n\n";
    flush();
    usleep(75000);
}

$conn->close();
echo "event: reconnect\ndata: " . json_encode(['last_id' => $cursor]) . "\n\n";
flush();
