<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationSettingsController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function edit(): Response
    {
        $org = $this->organization();

        return Inertia::render('organization/Settings', [
            'organization' => $org->only(['id', 'name', 'country', 'vat_number', 'default_locale', 'timezone', 'automation_kill_switch', 'billing_email', 'data_retention_days']),
            'canManage' => $this->membership()->role->canManageOrganization(),
            'isOwner' => $this->membership()->role->value === 'owner',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizeManage();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'country' => ['required', 'string', 'size:2'],
            'vat_number' => ['nullable', 'string', 'max:30'],
            'default_locale' => ['required', 'in:nl,en,de,fr'],
            'timezone' => ['required', 'timezone'],
            'billing_email' => ['nullable', 'email'],
            'data_retention_days' => ['nullable', 'integer', 'between:30,3650'],
        ]);

        $org = $this->organization();
        $org->update($data);
        $this->audit->log('organization.updated', $org, $data);

        return $this->backWith('Organisation saved.');
    }

    public function killSwitch(Request $request): RedirectResponse
    {
        $this->authorizeManage();
        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        $org = $this->organization();
        $org->forceFill(['automation_kill_switch' => $enabled])->save();
        $this->audit->log($enabled ? 'automation.paused' : 'automation.resumed', $org);

        return $this->backWith($enabled ? 'All automatic publishing is paused.' : 'Automatic publishing resumed.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        abort_unless($this->membership()->role->value === 'owner', 403);
        $request->validate(['confirm' => ['required', 'in:'.$this->organization()->name]]);

        $org = $this->organization();
        $this->audit->log('organization.deleted', $org);
        $org->delete(); // soft delete; PurgeDeletedOrganizations removes data after the grace period

        $request->user()->forceFill(['current_organization_id' => null])->save();
        $this->toast('Organisation scheduled for deletion.');

        return redirect()->route('onboarding.create');
    }

    public function audit(Request $request): Response
    {
        $this->authorizeManage();

        $logs = AuditLog::query()
            ->when($request->string('action')->toString(), fn ($q, $a) => $q->where('action', 'like', $a.'%'))
            ->latest('created_at')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AuditLog $l) => [
                'id' => $l->id,
                'at' => $l->created_at?->toIso8601String(),
                'action' => $l->action,
                'actor' => $l->actor_type === 'user' ? (User::find($l->actor_id)?->name ?? 'User #'.$l->actor_id) : ucfirst($l->actor_type),
                'subject' => $l->subject_type ? "{$l->subject_type} ".substr((string) $l->subject_id, 0, 8) : null,
                'changes' => $l->changes,
                'ip' => $l->ip,
            ]);

        return Inertia::render('organization/Audit', ['logs' => $logs, 'filters' => ['action' => $request->string('action')->toString()]]);
    }
}
