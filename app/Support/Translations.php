<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/** JSON translations shared with the Vue frontend (English source text is the key). */
class Translations
{
    /** @return array<string, string> */
    public static function for(string $locale): array
    {
        $path = lang_path("{$locale}.json");

        if (! is_file($path)) {
            return [];
        }

        return Cache::remember("translations:{$locale}:".filemtime($path), now()->addDay(), fn () => json_decode((string) file_get_contents($path), true) ?: []);
    }
}
