<?php
/**
 * Detect Workplace items that are SlayMeet video recordings (stored as type "file"
 * with JSON content, or mis-typed legacy rows that still carry video metadata).
 */
function slayly_workplace_item_is_meet_recording_video(array $item): bool
{
    $raw = (string) ($item['content'] ?? '');
    $meta = json_decode($raw, true);
    if (!is_array($meta)) {
        return false;
    }
    $mime = strtolower((string) ($meta['mime'] ?? ''));
    $path = strtolower((string) ($meta['path'] ?? ''));
    if ($mime !== '' && strncmp($mime, 'video/', 6) === 0) {
        return true;
    }
    if ($path !== '' && strpos($path, 'meet_recordings') !== false) {
        return preg_match('/\.(webm|mp4|ogg|mov)(\?|$)/i', $path) === 1;
    }

    return false;
}
