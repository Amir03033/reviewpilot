<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the user's current organisation and activates the tenant context.
 * Users without an organisation are sent to onboarding.
 */
class ResolveOrganization
{
    public function __construct(private TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $membership = $user->current_organization_id ? $user->membershipFor($user->current_organization_id) : null;

        if (! $membership) {
            $membership = $user->memberships()->oldest()->first();

            if (! $membership) {
                return redirect()->route('onboarding.create');
            }

            $user->forceFill(['current_organization_id' => $membership->organization_id])->save();
        }

        $organization = Organization::find($membership->organization_id);

        if (! $organization) {
            return redirect()->route('onboarding.create');
        }

        $this->tenant->set($organization, $membership);

        return $next($request);
    }
}
