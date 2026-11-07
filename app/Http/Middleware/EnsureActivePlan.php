<?php

namespace App\Http\Middleware;

use App\Services\Billing\Entitlements;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Paid actions (publishing replies, AI drafting, importing locations) need an active
 * trial or subscription. Reading existing data stays available.
 */
class EnsureActivePlan
{
    public function __construct(private TenantContext $tenant, private Entitlements $entitlements) {}

    public function handle(Request $request, Closure $next): Response
    {
        $organization = $this->tenant->organization();

        if ($organization && ! $this->entitlements->isActive($organization)) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => 'An active subscription is required.'], 402);
            }

            Inertia::flash('toast', ['type' => 'error', 'message' => 'Your trial or subscription has ended. Choose a plan to continue.']);

            return redirect()->route('billing.index');
        }

        return $next($request);
    }
}
