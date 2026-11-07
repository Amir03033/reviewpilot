<?php

namespace App\Http\Middleware;

use App\Enums\ReviewState;
use App\Models\Organization;
use App\Models\Review;
use App\Services\Billing\Entitlements;
use App\Services\Google\GoogleOAuth;
use App\Support\PlatformSettings;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
            ],
            // Lazy: evaluated at render time, after ResolveOrganization has run.
            'tenant' => fn () => $this->tenant($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    private function tenant(Request $request): ?array
    {
        $tenant = app(TenantContext::class);
        $organization = $tenant->organization();
        $membership = $tenant->membership();
        $user = $request->user();

        return $organization ? [
            'organization' => $organization->only(['id', 'name', 'slug', 'automation_kill_switch']),
            'role' => $membership?->role->value,
            'canManage' => (bool) $membership?->role->canManageOrganization(),
            'canBill' => (bool) $membership?->role->canManageBilling(),
            'plan' => app(Entitlements::class)->plan($organization),
            'onTrial' => $organization->onTrial(),
            'trialEndsAt' => $organization->trial_ends_at?->toIso8601String(),
            'pendingApprovals' => Review::query()->accessibleBy($membership)->visible()
                ->whereIn('workflow_state', ReviewState::inboxAttention())->count(),
            'organizations' => $user?->memberships()->get()->map(fn ($m) => [
                'id' => $m->organization_id,
                'name' => Organization::query()->whereKey($m->organization_id)->value('name'),
            ])->values(),
            'automationPausedGlobally' => PlatformSettings::automationPaused(),
            'demoMode' => [
                'ai' => ! in_array(config('reviewpilot.ai.provider'), ['anthropic', 'claude'], true),
                'google' => ! app(GoogleOAuth::class)->isConfigured(),
            ],
        ] : null;
    }
}
