<?php

namespace Tests\Feature\ReviewPilot;

use App\Enums\LocationRole;
use App\Enums\OrganizationRole;
use App\Models\LocationMembership;
use App\Models\OrganizationMembership;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_users_cannot_see_another_organizations_reviews_or_locations(): void
    {
        [$ownerA, $orgA] = $this->makeOrganization(name: 'Org A');
        $reviewA = $this->makeReview($locationA = $this->makeLocation($orgA));

        [$ownerB, $orgB] = $this->makeOrganization(name: 'Org B');
        $this->makeReview($this->makeLocation($orgB));

        $this->actingAs($ownerB);
        $this->get(route('reviews.show', $reviewA->id))->assertNotFound();
        $this->get(route('locations.show', $locationA->id))->assertNotFound();
        $this->post(route('reviews.reply', $reviewA->id), ['text' => 'hi'])->assertNotFound();
        $this->patch(route('locations.update', $locationA->id), ['name' => 'Hacked'])->assertNotFound();

        $this->get(route('reviews.index', ['status' => 'all']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('reviews.total', 1));
    }

    public function test_global_scope_limits_queries_to_the_active_organization(): void
    {
        [, $orgA] = $this->makeOrganization(name: 'Org A');
        $this->makeReview($this->makeLocation($orgA));
        [, $orgB] = $this->makeOrganization(name: 'Org B');
        $this->makeReview($this->makeLocation($orgB));

        $this->assertSame(2, Review::query()->count()); // no context: system scope
        $this->assertSame(1, $this->inTenant($orgA, fn () => Review::query()->count()));
    }

    public function test_location_scoped_members_only_see_their_locations(): void
    {
        [, $org] = $this->makeOrganization();
        $mine = $this->makeLocation($org);
        $other = $this->makeLocation($org);
        $this->makeReview($mine);
        $otherReview = $this->makeReview($other);

        $manager = User::factory()->create(['current_organization_id' => $org->id]);
        $membership = OrganizationMembership::withoutGlobalScopes()->create([
            'organization_id' => $org->id, 'user_id' => $manager->id, 'role' => OrganizationRole::Member,
        ]);
        LocationMembership::withoutGlobalScopes()->create([
            'organization_id' => $org->id, 'membership_id' => $membership->id, 'location_id' => $mine->id, 'role' => LocationRole::Manager,
        ]);

        $this->actingAs($manager);
        $this->get(route('reviews.show', $otherReview->id))->assertNotFound();
        $this->get(route('reviews.index', ['status' => 'all']))
            ->assertInertia(fn ($page) => $page->where('reviews.total', 1));
    }

    public function test_viewers_cannot_approve_replies(): void
    {
        [, $org] = $this->makeOrganization();
        $location = $this->makeLocation($org);
        $review = $this->makeReview($location);

        $analyst = User::factory()->create(['current_organization_id' => $org->id]);
        OrganizationMembership::withoutGlobalScopes()->create([
            'organization_id' => $org->id, 'user_id' => $analyst->id, 'role' => OrganizationRole::Analyst,
        ]);

        $this->actingAs($analyst)->post(route('reviews.reply', $review->id), ['text' => 'Thanks!'])->assertForbidden();
    }
}
