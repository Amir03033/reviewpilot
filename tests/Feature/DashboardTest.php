<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_users_without_an_organization_are_sent_to_onboarding()
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))->assertRedirect(route('onboarding.create'));
    }

    public function test_members_can_visit_the_dashboard()
    {
        [$owner, $org] = $this->makeOrganization();
        $this->makeReview($this->makeLocation($org));

        $this->actingAs($owner)->get(route('dashboard'))->assertOk();
    }

    public function test_onboarding_creates_an_organization_with_trial()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('onboarding.store'), ['name' => 'Café Zon', 'country' => 'NL', 'accept_terms' => true])
            ->assertRedirect(route('integrations.index'));

        $user->refresh();
        $this->assertNotNull($user->current_organization_id);
        $this->assertTrue($user->currentOrganization->onTrial());
        $this->assertSame('owner', $user->membershipFor($user->current_organization_id)->role->value);
    }
}
