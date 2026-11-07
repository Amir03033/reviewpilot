<?php

namespace App\Http\Controllers;

use App\Models\GoogleConnection;
use App\Models\Location;
use App\Services\Insights\DashboardStats;
use App\Services\Organizations\OnboardingChecklist;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, OnboardingChecklist $checklist): Response
    {
        $locationId = $request->string('location')->toString() ?: null;
        if ($locationId) {
            $this->authorizeLocation('view-location', $locationId);
        }

        $stats = new DashboardStats($this->membership(), $locationId);

        return Inertia::render('Dashboard', [
            'today' => $stats->today(),
            'kpis' => $stats->kpis(30),
            'trend' => $stats->trend(90),
            'topics' => $stats->topics(30, 6),
            'locations' => $stats->locations(30),
            'filters' => ['location' => $locationId],
            'checklist' => $this->membership()->role->canManageOrganization() ? $checklist->for($this->organization()) : null,
            'setup' => [
                'google_connected' => GoogleConnection::query()->exists(),
                'has_locations' => Location::query()->exists(),
            ],
        ]);
    }

    public function dismissChecklist(): RedirectResponse
    {
        $this->authorizeManage();
        $org = $this->organization();
        $org->forceFill(['settings' => [...($org->settings ?? []), 'onboarding_dismissed' => true]])->save();

        return back();
    }
}
