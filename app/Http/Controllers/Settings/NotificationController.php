<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Per-user, per-organisation email preferences. */
class NotificationController extends Controller
{
    public function edit(Request $request): Response
    {
        $memberships = OrganizationMembership::withoutGlobalScopes()
            ->where('user_id', $request->user()->id)->get();

        return Inertia::render('settings/Notifications', [
            'memberships' => $memberships->map(fn (OrganizationMembership $m) => [
                'id' => $m->id,
                'organization' => Organization::query()->whereKey($m->organization_id)->value('name'),
                'role' => $m->role->value,
                'settings' => $m->notificationSettings(),
            ])->values(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $keys = array_keys(OrganizationMembership::NOTIFICATION_DEFAULTS);
        $data = $request->validate([
            'membership_id' => ['required', 'string'],
            'settings' => ['required', 'array'],
            'settings.*' => ['boolean'],
        ]);

        // Only your own memberships; unknown keys are ignored.
        $membership = OrganizationMembership::withoutGlobalScopes()
            ->where('user_id', $request->user()->id)
            ->findOrFail($data['membership_id']);

        $membership->forceFill([
            'notification_preferences' => collect($data['settings'])->only($keys)->map(fn ($v) => (bool) $v)->all(),
        ])->save();

        return $this->backWith('Notification settings saved.');
    }
}
