<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Platform-wide switches stored in the database (never only in cache). */
class PlatformSettings
{
    public const AUTOMATION_PAUSED = 'automation_paused';

    public static function get(string $key, ?string $default = null): ?string
    {
        try {
            return DB::table('platform_settings')->where('key', $key)->value('value') ?? $default;
        } catch (QueryException) {
            return $default; // tables not migrated yet (fresh install)
        }
    }

    public static function set(string $key, ?string $value): void
    {
        DB::table('platform_settings')->updateOrInsert(['key' => $key], ['value' => $value, 'updated_at' => now(), 'created_at' => now()]);
    }

    /** Global emergency stop for ALL automatic publishing (env flag or DB switch). */
    public static function automationPaused(): bool
    {
        return (bool) config('reviewpilot.automation.globally_disabled') || self::get(self::AUTOMATION_PAUSED) === '1';
    }
}
