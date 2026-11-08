<?php

namespace App\Http\Controllers;

use App\Support\Sectors;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Welcome', [
            'trialDays' => (int) config('reviewpilot.trial_days'),
            'sectors' => Sectors::options(app()->getLocale()),
            'plans' => collect(config('reviewpilot.plans'))->map(fn (array $p, string $key) => [
                'key' => $key,
                'name' => $p['name'],
                'price_per_location' => $p['price_per_location'],
                'min_locations' => $p['min_locations'] ?? 1,
                'ai_reviews_per_location' => $p['ai_reviews_per_location'],
                'max_users' => $p['max_users'],
                'modes' => $p['modes'],
                'features' => $p['features'],
            ])->values(),
        ]);
    }
}
