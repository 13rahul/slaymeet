<?php
declare(strict_types=1);

namespace Slayly\Modules\SlayMeet\Domain;

/**
 * Room lifecycle (join, leave, create) — phase 2 service shell.
 * Logic currently lives in Http/Api/*.php scripts; migrate incrementally.
 */
final class RoomService
{
    public static function moduleApiDir(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Http' . DIRECTORY_SEPARATOR . 'Api';
    }
}
