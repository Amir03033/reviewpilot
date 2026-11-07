<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\OrganizationResource\Pages\ViewOrganization;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private const PAGES = ['/admin', '/admin/organizations', '/admin/users', '/admin/google-connections',
        '/admin/gbp-location-links', '/admin/ai-generations', '/admin/audit-logs', '/admin/failed-jobs'];

    private function admin(array $attributes = []): User
    {
        return User::factory()->create(['two_factor_confirmed_at' => now(), ...$attributes])
            ->forceFill(['is_platform_admin' => true]);
    }

    public function test_guests_are_sent_to_the_normal_login(): void
    {
        $this->get('/admin')->assertRedirect();
        $this->get('/admin/login')->assertRedirect(route('login'));
    }

    public function test_customers_cannot_open_the_admin_panel(): void
    {
        [$owner] = $this->makeOrganization();

        $this->actingAs($owner)->get('/admin')->assertForbidden();
    }

    public function test_admins_need_two_factor_when_required(): void
    {
        config(['reviewpilot.admin.require_two_factor' => true]);
        $admin = $this->admin(['two_factor_confirmed_at' => null]);
        $admin->save();

        $this->actingAs($admin)->get('/admin')->assertForbidden();
    }

    public function test_every_admin_page_loads_with_data(): void
    {
        config(['reviewpilot.admin.require_two_factor' => true]);
        [, $org] = $this->makeOrganization();
        $this->makeLocation($org);
        $admin = $this->admin();
        $admin->save();

        foreach (self::PAGES as $page) {
            $this->actingAs($admin)->get($page)->assertOk();
        }
        $this->actingAs($admin)->get("/admin/organizations/{$org->id}")->assertOk()->assertSee($org->name);
    }

    public function test_make_admin_command(): void
    {
        $user = User::factory()->create(['email' => 'boss@example.com']);

        $this->artisan('reviewpilot:make-admin', ['email' => 'boss@example.com'])->assertSuccessful();
        $this->assertTrue($user->fresh()->is_platform_admin);

        $this->artisan('reviewpilot:make-admin', ['email' => 'boss@example.com', '--revoke' => true])->assertSuccessful();
        $this->assertFalse($user->fresh()->is_platform_admin);

        $this->artisan('reviewpilot:make-admin', ['email' => 'nobody@example.com'])->assertFailed();
    }

    public function test_is_platform_admin_cannot_be_mass_assigned(): void
    {
        $user = User::factory()->create();
        $user->fill(['is_platform_admin' => true])->save();

        $this->assertFalse($user->fresh()->is_platform_admin);
    }

    public function test_admin_actions_are_applied_and_audited(): void
    {
        [, $org] = $this->makeOrganization();
        $admin = $this->admin();
        $admin->save();
        $this->actingAs($admin);
        $before = $org->trial_ends_at;

        Livewire::test(ViewOrganization::class, ['record' => $org->id])->callAction('extendTrial');
        Livewire::test(ViewOrganization::class, ['record' => $org->id])->callAction('toggleKillSwitch');
        Livewire::test(Dashboard::class)->callAction('toggleAutomation');

        $org->refresh();
        $this->assertTrue($org->trial_ends_at->eq($before->copy()->addDays(14)));
        $this->assertTrue($org->automation_kill_switch);
        $this->assertTrue(PlatformSettings::automationPaused());
        $this->assertSame(2, AuditLog::withoutGlobalScopes()->where('actor_type', 'platform_admin')->where('organization_id', $org->id)->count());
    }
}
