<?php

namespace Tests\Feature;

use App\Enums\AutomationMode;
use App\Enums\ReviewState;
use App\Jobs\ProcessReview;
use App\Models\Topic;
use App\Models\User;
use App\Services\Ai\LlmClient;
use App\Services\Reviews\DraftGenerator;
use App\Services\Reviews\ReviewAnalyzer;
use App\Services\Reviews\ReviewPipeline;
use App\Support\Sectors;
use App\Support\TopicTaxonomy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsTenants;
use Tests\Support\RecordingLlmClient;
use Tests\TestCase;

class SectorTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private RecordingLlmClient $llm;

    protected function setUp(): void
    {
        parent::setUp();
        config(['reviewpilot.ai.provider' => 'fake']);
        Notification::fake();
        $this->llm = app(RecordingLlmClient::class);
        $this->app->instance(LlmClient::class, $this->llm);
    }

    public function test_sector_is_chosen_at_onboarding_and_defaults_to_hospitality(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/onboarding')->assertInertia(fn ($page) => $page->has('sectors', count(Sectors::SECTORS)));

        $this->actingAs($user)->post('/onboarding', ['name' => 'Salon Lisa', 'country' => 'NL', 'sector' => 'beauty', 'accept_terms' => '1'])->assertRedirect();
        $this->assertSame('beauty', $user->fresh()->currentOrganization->sector);

        $this->actingAs($user)->post('/onboarding', ['name' => 'X', 'country' => 'NL', 'sector' => 'casino', 'accept_terms' => '1'])->assertSessionHasErrors('sector');

        [, $org] = $this->makeOrganization();
        $this->assertSame('hospitality', $org->sector);
    }

    public function test_every_sector_has_its_own_topics_and_prompt(): void
    {
        foreach (Sectors::keys() as $sector) {
            [, $org] = $this->makeOrganization(name: "Org {$sector}");
            $org->forceFill(['sector' => $sector])->save();
            $review = $this->makeReview($this->makeLocation($org), 4, 'Prima geholpen.');

            $request = app(ReviewAnalyzer::class)->request($review);
            $allowed = $request->schema['properties']['topics']['items']['properties']['key']['enum'];

            $this->assertSame(TopicTaxonomy::leafKeys($sector), $allowed);
            $this->assertContains('service.friendliness', $allowed, 'shared topics everywhere');
            $this->assertStringContainsString(Sectors::get($sector)['business'], $request->system);
        }

        $this->assertContains('food.taste', TopicTaxonomy::leafKeys('hospitality'));
        $this->assertNotContains('food.taste', TopicTaxonomy::leafKeys('automotive'));
        $this->assertContains('repair.quote', TopicTaxonomy::leafKeys('automotive'));
        $this->assertContains('stay.bed', TopicTaxonomy::leafKeys('hotel'));
    }

    public function test_healthcare_never_publishes_automatically_and_protects_confidentiality(): void
    {
        [$owner, $org] = $this->makeOrganization();
        $org->forceFill(['sector' => 'health'])->save();
        $location = $this->makeLocation($org, AutomationMode::Autopilot);
        $review = $this->makeReview($location, 5, 'Fijne praktijk, goed geholpen!');

        (new ProcessReview($review->id))->handle(app(ReviewPipeline::class));

        $this->assertSame(ReviewState::PendingApproval, $review->fresh()->workflow_state);
        $draftSystem = collect($this->llm->requests)->firstWhere('purpose', 'draft')->system;
        $this->assertStringContainsString('Never confirm or suggest that the reviewer is or was a patient', $draftSystem);

        $this->actingAs($owner)->get("/locations/{$location->id}")->assertInertia(fn ($page) => $page
            ->where('allowedModes', fn ($modes) => collect($modes)->where('allowed', true)->pluck('value')->all() === ['manual']));
    }

    public function test_medical_and_safety_complaints_always_need_a_person(): void
    {
        foreach (['automotive' => 'Na de reparatie deden de remmen het niet, levensgevaarlijk!', 'beauty' => 'Na de behandeling kreeg ik een ontsteking.'] as $sector => $text) {
            [, $org] = $this->makeOrganization(name: $sector);
            $org->forceFill(['sector' => $sector])->save();
            $review = $this->makeReview($this->makeLocation($org, AutomationMode::Autopilot), 4, $text);

            (new ProcessReview($review->id))->handle(app(ReviewPipeline::class));

            $review->refresh();
            $this->assertSame(ReviewState::PendingApproval, $review->workflow_state, $sector);
            $this->assertNotEmpty(array_intersect(['medical', 'safety_hazard'], $review->analysis->risk_flags), $sector);
        }
    }

    public function test_sector_reply_rules_are_in_the_drafting_prompt(): void
    {
        [, $org] = $this->makeOrganization();
        $org->forceFill(['sector' => 'retail'])->save();

        app(DraftGenerator::class)->generate($this->makeReview($this->makeLocation($org), 5, 'Leuke winkel'), null);

        $system = collect($this->llm->requests)->firstWhere('purpose', 'draft')->system;
        $this->assertStringContainsString('a shop', $system);
        $this->assertStringContainsString('Never promise a refund', $system);
    }

    public function test_new_topics_are_added_to_an_existing_database(): void
    {
        Topic::query()->where('key', 'stay.bed')->delete();

        $this->assertArrayHasKey('stay.bed', TopicTaxonomy::idMap());
    }

    public function test_sector_can_be_changed_in_settings_and_homepage_lists_sectors(): void
    {
        [$owner, $org] = $this->makeOrganization();

        $this->actingAs($owner)->put('/organization', [
            'name' => $org->name, 'country' => 'NL', 'default_locale' => 'nl', 'timezone' => 'Europe/Amsterdam', 'sector' => 'hotel',
        ])->assertRedirect();
        $this->assertSame('hotel', $org->fresh()->sector);

        $this->get('/')->assertInertia(fn ($page) => $page->has('sectors', count(Sectors::SECTORS)));
    }
}
