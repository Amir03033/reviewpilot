<?php

namespace App\Http\Controllers;

use App\Enums\LocationRole;
use App\Enums\OrganizationRole;
use App\Models\Invitation;
use App\Models\Location;
use App\Models\LocationMembership;
use App\Models\OrganizationMembership;
use App\Notifications\InvitationSent;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): Response
    {
        $members = OrganizationMembership::query()->with(['user', 'locationMemberships'])->get();

        return Inertia::render('team/Index', [
            'members' => $members->map(fn (OrganizationMembership $m) => [
                'id' => $m->id,
                'name' => $m->user?->name,
                'email' => $m->user?->email,
                'role' => $m->role->value,
                'two_factor' => (bool) $m->user?->two_factor_confirmed_at,
                'locations' => $m->locationMemberships->map(fn ($lm) => ['id' => $lm->location_id, 'role' => $lm->role->value]),
                'is_self' => $m->user_id === auth()->id(),
            ]),
            'invitations' => Invitation::query()->whereNull('accepted_at')->where('expires_at', '>', now())->latest()->get()
                ->map(fn ($i) => ['id' => $i->id, 'email' => $i->email, 'role' => $i->role->value, 'expires_at' => $i->expires_at->toIso8601String()]),
            'locations' => Location::query()->orderBy('name')->get(['id', 'name']),
            'roles' => array_map(fn ($r) => $r->value, OrganizationRole::cases()),
            'locationRoles' => array_map(fn ($r) => $r->value, LocationRole::cases()),
            'canManage' => $this->membership()->role->canManageOrganization(),
        ]);
    }

    public function invite(Request $request, Entitlements $entitlements): RedirectResponse
    {
        $this->authorizeManage();

        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'role' => ['required', Rule::in(['admin', 'analyst', 'billing', 'member'])],
            'location_ids' => ['array'],
            'location_ids.*' => ['string', Rule::exists('locations', 'id')->where('organization_id', $this->organization()->id)],
            'location_role' => ['nullable', Rule::enum(LocationRole::class)],
        ]);

        $maxUsers = $entitlements->planConfig($this->organization())['max_users'] ?? null;
        if ($maxUsers && OrganizationMembership::query()->count() >= $maxUsers) {
            return $this->backWith('Your plan includes :count users. Upgrade to invite more.', 'error', ['count' => $maxUsers]);
        }

        $token = Str::random(48);
        $invitation = Invitation::query()->create([
            'email' => strtolower($data['email']),
            'role' => $data['role'],
            'location_ids' => $data['location_ids'] ?? [],
            'location_role' => $data['location_role'] ?? LocationRole::Manager->value,
            'token_hash' => hash('sha256', $token),
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(7),
        ]);

        Notification::route('mail', $invitation->email)->notify(new InvitationSent($invitation, $token));
        $this->audit->log('member.invited', $invitation, ['email' => $invitation->email, 'role' => $data['role']]);

        return $this->backWith('Invitation sent to :email.', replace: ['email' => $invitation->email]);
    }

    public function update(Request $request, string $membershipId): RedirectResponse
    {
        $this->authorizeManage();
        $membership = OrganizationMembership::query()->findOrFail($membershipId);

        $data = $request->validate([
            'role' => ['required', Rule::enum(OrganizationRole::class)],
            'locations' => ['array'],
            'locations.*.id' => ['required', 'string', Rule::exists('locations', 'id')->where('organization_id', $this->organization()->id)],
            'locations.*.role' => ['required', Rule::enum(LocationRole::class)],
        ]);

        if ($membership->role === OrganizationRole::Owner && $this->membership()->role !== OrganizationRole::Owner) {
            return $this->backWith('Only owners can change another owner.', 'error');
        }
        if ($membership->role === OrganizationRole::Owner && $data['role'] !== 'owner' && $this->ownerCount() <= 1) {
            return $this->backWith('An organisation needs at least one owner.', 'error');
        }
        if ($data['role'] === 'owner' && $this->membership()->role !== OrganizationRole::Owner) {
            return $this->backWith('Only owners can make someone an owner.', 'error');
        }

        DB::transaction(function () use ($membership, $data) {
            $membership->update(['role' => $data['role']]);
            LocationMembership::query()->where('membership_id', $membership->id)->delete();

            foreach ($data['locations'] ?? [] as $loc) {
                LocationMembership::query()->create(['membership_id' => $membership->id, 'location_id' => $loc['id'], 'role' => $loc['role']]);
            }
        });

        $this->audit->log('member.updated', $membership, $data);

        return $this->backWith('Member updated.');
    }

    public function destroy(string $membershipId): RedirectResponse
    {
        $this->authorizeManage();
        $membership = OrganizationMembership::query()->findOrFail($membershipId);

        if ($membership->role === OrganizationRole::Owner && $this->membership()->role !== OrganizationRole::Owner) {
            return $this->backWith('Only owners can remove an owner.', 'error');
        }
        if ($membership->role === OrganizationRole::Owner && $this->ownerCount() <= 1) {
            return $this->backWith('You cannot remove the last owner.', 'error');
        }

        $membership->delete();
        $this->audit->log('member.removed', $membership, ['user_id' => $membership->user_id]);

        return $this->backWith('Member removed.');
    }

    public function revokeInvitation(string $invitationId): RedirectResponse
    {
        $this->authorizeManage();
        Invitation::query()->findOrFail($invitationId)->delete();

        return $this->backWith('Invitation revoked.');
    }

    private function ownerCount(): int
    {
        return OrganizationMembership::query()->where('role', OrganizationRole::Owner->value)->count();
    }
}
