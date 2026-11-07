<?php

namespace Tests\Feature\Production;

use App\Enums\AutomationMode;
use App\Enums\LocationRole;
use App\Enums\OrganizationRole;
use App\Jobs\ProcessReview;
use App\Models\GbpLocationLink;
use App\Models\GoogleConnection;
use App\Models\Invitation;
use App\Models\Location;
use App\Models\LocationMembership;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Reviews\ReviewPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TenantSecurityTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Http::fake();
    }

    /** Org A's resources attacked by org B's owner, through every route that takes an ID. */
    public function test_no_route_accepts_another_organizations_ids(): void
    {
        [$ownerA, $orgA] = $this->makeOrganization(name: 'Victim');
        $locationA = $this->makeLocation($orgA, AutomationMode::Manual);
        $reviewA = $this->makeReview($locationA, 2, 'Slecht');
        (new ProcessReview($reviewA->id))->handle(app(ReviewPipeline::class));
        $draftA = $reviewA->drafts()->withoutGlobalScopes()->first();
        $membershipA = OrganizationMembership::withoutGlobalScopes()->where('organization_id', $orgA->id)->first();
        $invitationA = Invitation::withoutGlobalScopes()->create(['organization_id' => $orgA->id, 'email' => 'x@y.z', 'role' => 'member', 'token_hash' => hash('sha256', 't'), 'expires_at' => now()->addDay()]);
        $connectionA = GoogleConnection::withoutGlobalScopes()->where('organization_id', $orgA->id)->first();
        $subscriptionA = Subscription::withoutGlobalScopes()->create(['organization_id' => $orgA->id, 'plan' => 'pro', 'status' => 'active', 'quantity' => 1, 'amount_cents' => 6900]);

        [$attacker] = $this->makeOrganization(name: 'Attacker');
        $this->actingAs($attacker);

        $attempts = [
            ['get', route('reviews.show', $reviewA->id)],
            ['post', route('drafts.approve', $draftA->id)],
            ['post', route('reviews.reply', $reviewA->id), ['text' => 'pwned']],
            ['post', route('reviews.regenerate', $reviewA->id)],
            ['post', route('reviews.reject', $reviewA->id)],
            ['post', route('reviews.cancel-scheduled', $reviewA->id)],
            ['post', route('reviews.retry', $reviewA->id)],
            ['get', route('locations.show', $locationA->id)],
            ['patch', route('locations.update', $locationA->id), ['name' => 'pwned']],
            ['put', route('locations.policy', $locationA->id), ['min_rating' => 3, 'max_risk_score' => 60, 'hold_minutes' => 15, 'daily_cap' => 200, 'business_hours_only' => false]],
            ['put', route('locations.facts', $locationA->id), ['facts' => []]],
            ['put', route('locations.voice', $locationA->id), ['reset' => true]],
            ['post', route('locations.sync', $locationA->id)],
            ['delete', route('locations.destroy', $locationA->id)],
            ['put', route('team.update', $membershipA->id), ['role' => 'member']],
            ['delete', route('team.destroy', $membershipA->id)],
            ['delete', route('team.invitations.revoke', $invitationA->id)],
            ['post', route('integrations.google.refresh', $connectionA->id)],
            ['delete', route('integrations.google.disconnect', $connectionA->id)],
            ['get', route('billing.return', $subscriptionA->id)],
            ['post', route('organizations.switch', $orgA->id)],
        ];

        foreach ($attempts as $attempt) {
            [$method, $url] = $attempt;
            $status = $this->{$method}($url, $attempt[2] ?? [])->getStatusCode();
            $this->assertContains($status, [403, 404], "{$method} {$url} returned {$status}");
        }

        // Nothing changed for the victim.
        $this->assertSame($locationA->name, $locationA->fresh()->name);
        $this->assertNull($locationA->fresh()->deleted_at);
        $this->assertSame('owner', $membershipA->fresh()->role->value);
        $this->assertSame('active', $connectionA->fresh()->status->value);
        $this->assertDatabaseMissing('review_replies', ['review_id' => $reviewA->id]);
        Http::assertNothingSent();
    }

    public function test_import_ignores_other_organizations_google_locations(): void
    {
        [, $orgA] = $this->makeOrganization(name: 'Victim');
        $this->makeLocation($orgA);
        $connection = GoogleConnection::withoutGlobalScopes()->where('organization_id', $orgA->id)->first();
        $spare = GbpLocationLink::withoutGlobalScopes()->create(['organization_id' => $orgA->id, 'gbp_account_id' => $connection->accounts()->withoutGlobalScopes()->first()->id, 'gbp_location_name' => 'locations/777', 'v4_parent' => 'accounts/111/locations/777', 'title' => 'Victim 2', 'is_verified' => true]);

        [$attacker, $orgB] = $this->makeOrganization(name: 'Attacker');
        $this->actingAs($attacker)->post(route('integrations.google.import'), ['links' => [$spare->id]]);

        $this->assertNull($spare->fresh()->location_id);
        $this->assertSame(0, Location::withoutGlobalScopes()->where('organization_id', $orgB->id)->count());
    }

    public function test_admins_cannot_demote_or_remove_owners(): void
    {
        [$owner, $org] = $this->makeOrganization();
        $admin = User::factory()->create(['current_organization_id' => $org->id]);
        OrganizationMembership::withoutGlobalScopes()->create(['organization_id' => $org->id, 'user_id' => $admin->id, 'role' => OrganizationRole::Admin]);
        $ownerMembership = $owner->membershipFor($org);

        $this->actingAs($admin)->put(route('team.update', $ownerMembership->id), ['role' => 'member']);
        $this->actingAs($admin)->delete(route('team.destroy', $ownerMembership->id));
        $this->actingAs($admin)->put(route('team.update', $admin->membershipFor($org)->id), ['role' => 'owner']);

        $this->assertSame('owner', $ownerMembership->fresh()->role->value);
        $this->assertSame('admin', $admin->membershipFor($org)->role->value);
    }

    public function test_members_cannot_manage_the_organization(): void
    {
        [, $org] = $this->makeOrganization();
        $location = $this->makeLocation($org);
        $member = User::factory()->create(['current_organization_id' => $org->id]);
        $m = OrganizationMembership::withoutGlobalScopes()->create(['organization_id' => $org->id, 'user_id' => $member->id, 'role' => OrganizationRole::Member]);
        LocationMembership::withoutGlobalScopes()->create(['organization_id' => $org->id, 'membership_id' => $m->id, 'location_id' => $location->id, 'role' => LocationRole::Contributor]);
        Payment::withoutGlobalScopes()->create(['organization_id' => $org->id, 'mollie_payment_id' => 'tr_x', 'sequence_type' => 'first', 'status' => 'paid', 'amount_cents' => 100]);

        $this->actingAs($member);
        $this->put(route('organization.update'), ['name' => 'x', 'country' => 'NL', 'default_locale' => 'nl', 'timezone' => 'UTC'])->assertForbidden();
        $this->put(route('organization.kill-switch'), ['enabled' => true])->assertForbidden();
        $this->delete(route('organization.destroy'), ['confirm' => $org->name])->assertForbidden();
        $this->post(route('team.invite'), ['email' => 'a@b.c', 'role' => 'admin'])->assertForbidden();
        $this->post(route('billing.checkout'), ['plan' => 'pro'])->assertForbidden();
        $this->post(route('integrations.google.connect'))->assertForbidden();
        $this->put(route('voice.update'), ['settings' => []])->assertForbidden();
        $this->get(route('organization.audit'))->assertForbidden();
        $this->get(route('billing.index'))->assertInertia(fn ($p) => $p->where('payments', []));

        // Contributors draft but never approve.
        $review = $this->makeReview($location, 4);
        $this->post(route('reviews.reply', $review->id), ['text' => 'hi'])->assertForbidden();
        $this->post(route('reviews.regenerate', $review->id))->assertRedirect();
    }

    public function test_mass_assignment_of_billing_and_trial_fields_is_ignored(): void
    {
        [$owner, $org] = $this->makeOrganization();

        $this->actingAs($owner)->put(route('organization.update'), [
            'name' => 'New', 'country' => 'NL', 'default_locale' => 'nl', 'timezone' => 'Europe/Amsterdam',
            'plan' => 'group', 'trial_ends_at' => now()->addYears(5)->toDateTimeString(), 'mollie_customer_id' => 'cst_evil', 'automation_kill_switch' => true,
        ])->assertRedirect();

        $org->refresh();
        $this->assertSame('New', $org->name);
        $this->assertNull($org->plan);
        $this->assertNull($org->mollie_customer_id);
        $this->assertFalse($org->automation_kill_switch);
        $this->assertTrue($org->trial_ends_at->lt(now()->addDays(15)));
    }

    public function test_invitations_require_the_invited_verified_email_and_expire(): void
    {
        [, $org] = $this->makeOrganization();
        $make = fn ($email, $expires) => Invitation::withoutGlobalScopes()->create(['organization_id' => $org->id, 'email' => $email, 'role' => 'admin', 'token_hash' => hash('sha256', $email), 'expires_at' => $expires]);
        $make('invitee@bistro.nl', now()->addDay());
        $make('late@bistro.nl', now()->subMinute());

        $wrong = User::factory()->create(['email' => 'someone@else.nl']);
        $this->actingAs($wrong)->post(route('invitations.accept', 'invitee@bistro.nl'));
        $this->assertNull($wrong->membershipFor($org));

        $unverified = User::factory()->unverified()->create(['email' => 'invitee@bistro.nl']);
        $this->actingAs($unverified)->post(route('invitations.accept', 'invitee@bistro.nl'))->assertRedirect(route('verification.notice'));
        $this->assertNull($unverified->membershipFor($org));

        $late = User::factory()->create(['email' => 'late@bistro.nl']);
        $this->actingAs($late)->post(route('invitations.accept', 'late@bistro.nl'))->assertNotFound();

        $unverified->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($unverified)->post(route('invitations.accept', 'invitee@bistro.nl'))->assertRedirect(route('dashboard'));
        $this->assertSame('admin', $unverified->fresh()->membershipFor($org)->role->value);
    }

    public function test_every_application_route_requires_authentication(): void
    {
        $public = ['home', 'webhooks.google', 'webhooks.mollie', 'invitations.show', 'well-known.passkeys'];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            $action = $route->getActionName();
            if (! $name || in_array($name, $public, true) || ! str_starts_with($action, 'App\\Http\\Controllers')) {
                continue;
            }
            $this->assertContains('auth', $route->gatherMiddleware(), "Route {$name} is not protected by auth");
        }
    }

    public function test_switching_to_an_organization_you_do_not_belong_to_is_refused(): void
    {
        [, $victim] = $this->makeOrganization(name: 'V');
        [$attacker] = $this->makeOrganization(name: 'A');

        $this->actingAs($attacker)->post(route('organizations.switch', $victim->id))->assertNotFound();
        $this->assertNotSame($victim->id, $attacker->fresh()->current_organization_id);

        // A forged current_organization_id is ignored by the middleware.
        $attacker->forceFill(['current_organization_id' => $victim->id])->save();
        $this->actingAs($attacker)->get(route('dashboard'))->assertOk();
        $this->assertNotSame($victim->id, $attacker->fresh()->current_organization_id);
        $this->assertSame(Organization::where('name', 'A')->value('id'), $attacker->fresh()->current_organization_id);
    }
}
