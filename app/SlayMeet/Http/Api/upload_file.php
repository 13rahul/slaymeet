<?php
/**
 * API: SlayMeet chat file upload — saves to storage/uploads/
 */

require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../includes/SlayGuard.php';

header('Content-Type: application/json; charset=utf-8');

$guard = SlayGuard::gatekeep([
    'rate_limit' => 'slaymeet_upload',
    'csrf' => true,
    'post_only' => true,
]);

try {
    if (!isset($_FILES['shared_file']) || $_FILES['shared_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file received');
    }

    $uploadDir = dirname(__DIR__, 4) . '/storage/uploads';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $originalName = basename((string) $_FILES['shared_file']['name']);
    $safeName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $originalName);
    $filename = time() . '_' . $safeName;
    $targetPath = $uploadDir . '/' . $filename;
    $relativeUrl = 'storage/uploads/' . $filename;

    if (!move_uploaded_file($_FILES['shared_file']['tmp_name'], $targetPath)) {
        throw new Exception('Failed to save shared file');
    }

    echo json_encode([
        'success' => true,
        'message' => 'File saved',
        'name' => $originalName,
        'url' => $relativeUrl,
        'mime' => $_FILES['shared_file']['type'] ?? 'application/octet-stream',
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
