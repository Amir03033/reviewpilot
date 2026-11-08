<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\Organizations\CreateOrganization;
use App\Support\Sectors;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        return Inertia::render('onboarding/CreateOrganization', [
            'hasOrganization' => $request->user()->memberships()->exists(),
            'sectors' => Sectors::options(app()->getLocale()),
        ]);
    }

    public function store(Request $request, CreateOrganization $create): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'country' => ['required', 'string', 'size:2'],
            'sector' => ['nullable', Rule::in(Sectors::keys())],
            'accept_terms' => ['accepted'],
        ]);

        $organization = $create->handle($request->user(), $data['name'], $data['country'], $data['sector'] ?? Sectors::DEFAULT);
        $organization->acceptTerms($request->user());

        $this->toast('Organisation created. Connect Google to import your locations.');

        return redirect()->route('integrations.index');
    }

    public function switch(Request $request, Organization $organization): RedirectResponse
    {
        abort_unless($request->user()->membershipFor($organization), 404);

        $request->user()->switchOrganization($organization);

        return redirect()->route('dashboard');
    }
}
