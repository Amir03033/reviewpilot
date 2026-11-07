<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Services\Reviews\BrandVoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BrandVoiceController extends Controller
{
    public function __construct(private BrandVoiceService $voices) {}

    public function edit(): Response
    {
        $voice = $this->voices->organizationVoice($this->organization()->id);
        $version = $voice?->currentVersion;
        $data = $version ? [
            'settings' => [...BrandVoiceService::defaults()['settings'], ...($version->settings ?? [])],
            'preferred_phrases' => $version->preferred_phrases ?? [],
            'forbidden_phrases' => $version->forbidden_phrases ?? [],
            'guidelines' => $version->guidelines,
            'special_instructions' => $version->special_instructions,
        ] : BrandVoiceService::defaults();

        return Inertia::render('voice/Edit', [
            'voice' => $data,
            'version' => $version?->version,
            'history' => $voice ? $voice->versions()->withoutGlobalScopes()->latest('version')->limit(10)->get()
                ->map(fn ($v) => ['version' => $v->version, 'created_at' => $v->created_at?->toIso8601String(), 'author' => $v->author?->name]) : [],
            'canManage' => $this->membership()->role->canManageOrganization(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizeManage();

        $voice = $this->voices->organizationVoice($this->organization()->id);
        $this->voices->saveVersion($voice, $this->validated($request), $request->user());

        return $this->backWith('Brand voice saved. New drafts will use it.');
    }

    public function updateLocation(Request $request, string $locationId): RedirectResponse
    {
        $location = Location::query()->findOrFail($locationId);
        $this->authorizeLocation('approve-for-location', $location->id);

        if ($request->boolean('reset')) {
            $this->voices->locationVoice($location)?->delete();

            return $this->backWith('Location now uses the organisation voice.');
        }

        $voice = $this->voices->locationVoice($location, create: true);
        $this->voices->saveVersion($voice, $this->validated($request, partial: true), $request->user());

        return $this->backWith('Location voice saved.');
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'nullable' : 'required';

        $data = $request->validate([
            'settings' => ['required', 'array'],
            'settings.formality' => [$required, 'integer', 'between:1,5'],
            'settings.warmth' => [$required, 'integer', 'between:1,5'],
            'settings.humor' => [$required, 'in:none,light,playful'],
            'settings.emoji' => [$required, 'in:never,positive_only,allowed'],
            'settings.length' => [$required, 'in:short,standard,detailed'],
            'settings.sign_off' => ['nullable', 'string', 'max:80'],
            'settings.language_mode' => [$required, 'in:auto,fixed'],
            'settings.fixed_language' => ['nullable', 'string', 'size:2'],
            'settings.compensation_policy' => [$required, 'in:none,invite_contact,may_offer'],
            'preferred_phrases' => ['nullable', 'array', 'max:30'],
            'preferred_phrases.*' => ['string', 'max:120'],
            'forbidden_phrases' => ['nullable', 'array', 'max:60'],
            'forbidden_phrases.*' => ['string', 'max:120'],
            'guidelines' => ['nullable', 'string', 'max:2000'],
            'special_instructions' => ['nullable', 'string', 'max:1000'],
        ]);

        $data['settings'] = array_filter($data['settings'], fn ($v) => $v !== null && $v !== '');

        return $data;
    }
}
