<?php
require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../includes/session_security.php';
require_once __DIR__ . '/../../../Core/Database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'incoming' => null, 'error' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];

try {
    $db = \Slayly\Core\Database::getInstance()->getConnection();

    $stmt = $db->prepare("
        SELECT c.*, u.name AS caller_name, u.profile_pic AS caller_avatar 
        FROM slaymeet_calls c
        JOIN users u ON c.caller_id = u.id
        WHERE c.receiver_id = ?
          AND c.caller_id <> ?
          AND c.status = 'ringing'
          AND c.updated_at >= DATE_SUB(NOW(), INTERVAL 3 MINUTE)
        ORDER BY c.id DESC
        LIMIT 1
    ");
    $stmt->execute([$userId, $userId]);
    $incoming = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'incoming' => $incoming ?: null]);
} catch (Throwable $e) {
    error_log('[poll_calls] ' . $e->getMessage());
    echo json_encode(['success' => true, 'incoming' => null]);
}
