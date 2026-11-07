<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the interface language: the user's saved choice, then the session (guests),
 * then the browser's preference, then Dutch.
 */
class SetLocale
{
    public const SUPPORTED = ['nl', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale
            ?? $request->session()->get('locale')
            ?? $request->getPreferredLanguage(self::SUPPORTED)
            ?? 'nl';

        App::setLocale(in_array($locale, self::SUPPORTED, true) ? $locale : 'nl');

        return $next($request);
    }
}
