<?php
declare(strict_types=1);

namespace Slayly\Modules\SlayMeet\Domain;

/**
 * WebRTC / waiting-room signal persistence (phase 2 facade).
 * Full extraction from signal_*.php endpoints is incremental.
 */
final class SignalService
{
    public static function pruneStaleSignals(\mysqli $conn, int $roomId): void
    {
        if (!class_exists('SlayMeetHelpers', false)) {
            require_once dirname(__DIR__) . '/Domain/slaymeet_helpers.php';
        }
        if (method_exists('SlayMeetHelpers', 'pruneRoomSignals')) {
            \SlayMeetHelpers::pruneRoomSignals($conn, $roomId);
        }
    }
}
