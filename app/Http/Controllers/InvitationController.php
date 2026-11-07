<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\LocationMembership;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController extends Controller
{
    public function show(string $token): Response
    {
        $invitation = $this->find($token);

        return Inertia::render('invitations/Accept', [
            'token' => $token,
            'invitation' => $invitation ? [
                'email' => $invitation->email,
                'organization' => Organization::find($invitation->organization_id)?->name,
                'role' => $invitation->role->value,
            ] : null,
        ]);
    }

    public function accept(Request $request, string $token, AuditLogger $audit): RedirectResponse
    {
        $invitation = $this->find($token) ?? abort(404);
        $user = $request->user();

        if (strtolower($user->email) !== $invitation->email) {
            $this->toast("This invitation is for {$invitation->email}. Log in with that address.", 'error');

            return redirect()->route('invitations.show', $token);
        }

        DB::transaction(function () use ($invitation, $user) {
            $membership = OrganizationMembership::withoutGlobalScopes()->firstOrCreate(
                ['organization_id' => $invitation->organization_id, 'user_id' => $user->id],
                ['role' => $invitation->role, 'invited_by' => $invitation->invited_by],
            );

            foreach ($invitation->location_ids ?? [] as $locationId) {
                LocationMembership::withoutGlobalScopes()->firstOrCreate(
                    ['membership_id' => $membership->id, 'location_id' => $locationId],
                    ['organization_id' => $invitation->organization_id, 'role' => $invitation->location_role ?? 'manager'],
                );
            }

            $invitation->forceFill(['accepted_at' => now()])->save();
            $user->forceFill(['current_organization_id' => $invitation->organization_id])->save();
        });

        $audit->log('member.joined', $invitation, ['user_id' => $user->id], organizationId: $invitation->organization_id);
        $this->toast('Welcome aboard!');

        return redirect()->route('dashboard');
    }

    private function find(string $token): ?Invitation
    {
        $invitation = Invitation::withoutGlobalScopes()->where('token_hash', hash('sha256', $token))->first();

        return $invitation?->isPending() ? $invitation : null;
    }
}
