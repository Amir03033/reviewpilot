<?php

namespace Tests\Feature\ReviewPilot;

use App\Jobs\ProcessReview;
use App\Services\Reviews\ReviewPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class PagesTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_all_main_pages_render_for_an_owner(): void
    {
        Notification::fake();
        [$owner, $org] = $this->makeOrganization();
        $location = $this->makeLocation($org);
        $review = $this->makeReview($location, 2, 'Lang wachten en koud eten.');
        (new ProcessReview($review->id))->handle(app(ReviewPipeline::class));

        $pages = [
            route('dashboard') => 'Dashboard',
            route('reviews.index') => 'reviews/Index',
            route('reviews.show', $review->id) => 'reviews/Show',
            route('approvals.index') => 'approvals/Index',
            route('locations.index') => 'locations/Index',
            route('locations.show', $location->id) => 'locations/Show',
            route('voice.edit') => 'voice/Edit',
            route('integrations.index') => 'integrations/Index',
            route('team.index') => 'team/Index',
            route('billing.index') => 'billing/Index',
            route('organization.edit') => 'organization/Settings',
            route('organization.audit') => 'organization/Audit',
        ];

        foreach ($pages as $url => $component) {
            $this->actingAs($owner)->get($url)
                ->assertOk()
                ->assertInertia(fn ($page) => $page->component($component)->has('tenant'));
        }
    }

    public function test_brand_voice_updates_create_a_new_version(): void
    {
        [$owner] = $this->makeOrganization();

        $this->actingAs($owner)->put(route('voice.update'), [
            'settings' => ['formality' => 4, 'warmth' => 3, 'humor' => 'none', 'emoji' => 'never', 'length' => 'short', 'sign_off' => 'Team Zon', 'language_mode' => 'auto', 'compensation_policy' => 'invite_contact'],
            'forbidden_phrases' => ['geen idee'],
            'preferred_phrases' => [],
        ])->assertRedirect();

        $this->actingAs($owner)->get(route('voice.edit'))
            ->assertInertia(fn ($page) => $page->where('version', 2)->where('voice.settings.sign_off', 'Team Zon'));
    }
}
