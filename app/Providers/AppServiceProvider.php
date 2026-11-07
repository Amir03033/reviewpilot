<?php

namespace App\Providers;

use App\Models\OrganizationMembership;
use App\Services\Ai\ClaudeClient;
use App\Services\Ai\FakeLlmClient;
use App\Services\Ai\LlmClient;
use App\Services\Billing\BillingGateway;
use App\Services\Billing\MollieBillingGateway;
use App\Services\Google\GbpClient;
use App\Services\Google\HttpGbpClient;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One tenant context per request / job (reset between Octane requests and queued jobs).
        $this->app->scoped(TenantContext::class);

        $this->app->bind(LlmClient::class, fn ($app) => match (config('reviewpilot.ai.provider')) {
            'anthropic', 'claude' => $app->make(ClaudeClient::class),
            default => $app->make(FakeLlmClient::class),
        });

        $this->app->bind(GbpClient::class, HttpGbpClient::class);
        $this->app->bind(BillingGateway::class, MollieBillingGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureRateLimits();
    }

    protected function configureAuthorization(): void
    {
        $membership = fn (): ?OrganizationMembership => app(TenantContext::class)->membership();

        Gate::define('manage-organization', fn ($user) => (bool) $membership()?->role->canManageOrganization());
        Gate::define('manage-billing', fn ($user) => (bool) $membership()?->role->canManageBilling());
        Gate::define('view-location', fn ($user, string $locationId) => (bool) $membership()?->canAccessLocation($locationId));
        Gate::define('draft-for-location', fn ($user, string $locationId) => (bool) $membership()?->canDraftFor($locationId));
        Gate::define('approve-for-location', fn ($user, string $locationId) => (bool) $membership()?->canApproveFor($locationId));
    }

    protected function configureRateLimits(): void
    {
        RateLimiter::for('regenerate', fn (Request $request) => Limit::perMinute(10)->by((string) $request->user()?->id));
        RateLimiter::for('invitations', fn (Request $request) => Limit::perHour(30)->by((string) $request->user()?->id));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
