<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

abstract class Controller
{
    protected function tenant(): TenantContext
    {
        return app(TenantContext::class);
    }

    protected function organization(): Organization
    {
        return $this->tenant()->organization() ?? abort(403);
    }

    protected function membership(): OrganizationMembership
    {
        return $this->tenant()->membership() ?? abort(403);
    }

    protected function authorizeManage(): void
    {
        Gate::authorize('manage-organization');
    }

    protected function authorizeLocation(string $ability, string $locationId): void
    {
        // 404 rather than 403 so other tenants' IDs are not revealed.
        if (! $this->membership()->canAccessLocation($locationId)) {
            abort(404);
        }

        Gate::authorize($ability, $locationId);
    }

    protected function toast(string $message, string $type = 'success'): void
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);
    }

    protected function backWith(string $message, string $type = 'success'): RedirectResponse
    {
        $this->toast($message, $type);

        return back();
    }
}
