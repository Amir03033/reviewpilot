<?php

namespace Tests\Feature\Production;

use App\Enums\AutomationMode;
use App\Enums\ReviewState;
use App\Jobs\ProcessReview;
use App\Models\AiGeneration;
use App\Models\BrandVoice;
use App\Models\LocationFact;
use App\Models\ReviewReply;
use App\Services\Ai\ClaudeClient;
use App\Services\Ai\DisabledLlmClient;
use App\Services\Ai\LlmClient;
use App\Services\Ai\LlmException;
use App\Services\Reviews\BrandVoiceService;
use App\Services\Reviews\ReviewPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsTenants;
use Tests\Support\RecordingLlmClient;
use Tests\TestCase;

/**
 * AI safety at the application level. Claude itself is replaced by a recording double:
 * these tests prove what we SEND and how we HANDLE answers, not what Claude writes.
 */
class AiSafetyTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private RecordingLlmClient $llm;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->llm = app(RecordingLlmClient::class);
        $this->app->instance(LlmClient::class, $this->llm);
    }

    private function process($review): void
    {
        (new ProcessReview($review->id))->handle(app(ReviewPipeline::class));
        $review->refresh();
    }

    private function cleanAnalysis(array $overrides = []): array
    {
        return [
            'language' => 'nl', 'language_confidence' => 0.99, 'sentiment_score' => 0.9, 'sentiment_label' => 'positive',
            'topics' => [], 'entities' => ['dishes' => [], 'staff_mentions' => [], 'visit_context' => 'dine_in'],
            'risk_flags' => [], 'risk_score' => 0, 'requires_human' => false, 'requires_human_reasons' => [],
            'confidence' => 0.95, 'summary' => 'Positive', 'keywords' => [], ...$overrides,
        ];
    }

    private function drafts(string $text, string $language = 'nl'): array
    {
        return ['variants' => array_map(fn ($k) => ['key' => $k, 'text' => $text, 'language' => $language, 'fact_keys_used' => []], ['short', 'standard', 'warm'])];
    }

    /** Sensitive reviews in several languages, all 5★ on Auto-Pilot, while the model claims they are harmless. */
    public static function sensitiveReviews(): array
    {
        return [
            'nl food poisoning' => ['Lekker, maar daarna ben ik ziek geworden en moest overgeven.'],
            'en food poisoning' => ['Great vibe but I got sick the next day, probably food poisoning.'],
            'de food poisoning' => ['Schönes Essen, aber danach Lebensmittelvergiftung.'],
            'fr food poisoning' => ['Bon service mais intoxication alimentaire le soir même.'],
            'allergy' => ['Ondanks mijn notenallergie zat er noten in de saus, allergische reactie gehad.'],
            'hair in food' => ['Prima, alleen een haar in mijn salade gevonden.'],
            'cockroach' => ['Saw a cockroach near the toilets, otherwise fine.'],
            'discrimination' => ['Het personeel was racistisch tegen mijn vriend.'],
            'legal threat' => ['I have contacted my lawyer about this.'],
            'health authority' => ['Ik heb de NVWA ingelicht.'],
            'harassment' => ['The manager harassed my daughter.'],
        ];
    }

    #[DataProvider('sensitiveReviews')]
    public function test_sensitive_reviews_are_never_auto_published_even_if_the_model_misses_them(string $text): void
    {
        [, $org] = $this->makeOrganization();
        $location = $this->makeLocation($org, AutomationMode::Autopilot);
        $location->policy()->forceFill(['min_rating' => 3, 'max_risk_score' => 60])->save();
        $this->llm->responses['analysis'] = $this->cleanAnalysis(); // model says: harmless
        $this->llm->responses['draft'] = $this->drafts('Dank je wel voor je review! Team Bistro');

        $review = $this->makeReview($location, 5, $text);
        $this->process($review);

        $this->assertNotSame(ReviewState::ScheduledAuto, $review->workflow_state, "Auto-scheduled: {$text}");
        $this->assertNull(ReviewReply::withoutGlobalScopes()->where('review_id', $review->id)->first());
        $this->assertTrue($review->analysis->requires_human);
        $this->assertNotEmpty($review->analysis->risk_flags);
    }

    public function test_model_flagged_risks_are_respected(): void
    {
        [, $org] = $this->makeOrganization();
        $this->llm->responses['analysis'] = $this->cleanAnalysis(['risk_flags' => ['refund_request'], 'risk_score' => 15]);
        $review = $this->makeReview($this->makeLocation($org, AutomationMode::Autopilot), 5, 'Mooi, maar ik wil mijn geld terug voor de wijn.');

        $this->process($review);

        $this->assertSame(ReviewState::PendingApproval, $review->workflow_state);
    }

    public function test_prompt_contains_facts_voice_and_untrusted_content_warning(): void
    {
        [$owner, $org] = $this->makeOrganization();
        $location = $this->makeLocation($org, AutomationMode::Manual);
        LocationFact::withoutGlobalScopes()->create(['organization_id' => $org->id, 'location_id' => $location->id, 'key' => 'opening_hours', 'value' => 'Di-zo 17:00-22:00']);
        $voice = BrandVoice::withoutGlobalScopes()->where('organization_id', $org->id)->where('scope', 'organization')->first();
        app(BrandVoiceService::class)->saveVersion($voice, [
            'settings' => ['formality' => 5, 'warmth' => 2, 'humor' => 'none', 'emoji' => 'never', 'length' => 'short', 'sign_off' => 'Chef Anouk', 'language_mode' => 'auto', 'compensation_policy' => 'none'],
            'forbidden_phrases' => ['excuses voor het ongemak'],
            'preferred_phrases' => ['tot snel'],
            'guidelines' => 'Wij zijn een familierestaurant.',
        ], $owner);

        $this->process($this->makeReview($location, 4, 'Goed gegeten.'));

        $draft = collect($this->llm->requests)->firstWhere('purpose', 'draft');
        $this->assertStringContainsString('opening_hours: Di-zo 17:00-22:00', $draft->system);
        $this->assertStringContainsString('Use ONLY the facts listed below', $draft->system);
        $this->assertStringContainsString('untrusted content', $draft->system);
        $this->assertStringContainsString('Chef Anouk', $draft->system);
        $this->assertStringContainsString('formal (in Dutch use "u")', $draft->system);
        $this->assertStringContainsString('excuses voor het ongemak', $draft->system);
        $this->assertStringContainsString('Never use emoji', $draft->system);
        $this->assertStringContainsString('Never offer compensation', $draft->system);
        $this->assertStringContainsString('Wij zijn een familierestaurant.', $draft->system);
    }

    public function test_location_voice_overrides_organization_voice_but_cannot_unforbid_phrases(): void
    {
        [$owner, $org] = $this->makeOrganization();
        $location = $this->makeLocation($org);
        $service = app(BrandVoiceService::class);
        $service->saveVersion($service->locationVoice($location, create: true), [
            'settings' => ['sign_off' => 'Team Utrecht', 'emoji' => 'allowed'],
            'forbidden_phrases' => ['goedkoop'],
        ], $owner);

        $voice = $service->resolve($location);

        $this->assertSame('Team Utrecht', $voice['settings']['sign_off']);
        $this->assertSame('allowed', $voice['settings']['emoji']);
        $this->assertContains('goedkoop', $voice['forbidden_phrases']);
        $this->assertContains('sorry for the inconvenience', $voice['forbidden_phrases'], 'org forbidden phrases stay');
    }

    public function test_invented_specifics_block_publication_and_hand_over_to_a_person(): void
    {
        [, $org] = $this->makeOrganization();
        $this->llm->responses['analysis'] = $this->cleanAnalysis();
        $this->llm->responses['draft'] = $this->drafts('Dank je! Kom gerust terug, we zijn elke dag open tot 23:30 en een fles wijn kost maar €15.');

        $review = $this->makeReview($this->makeLocation($org, AutomationMode::Smart), 5, 'Heerlijk!');
        $this->process($review);

        $this->assertSame(ReviewState::NeedsHumanWriting, $review->workflow_state);
        $draft = $review->drafts()->withoutGlobalScopes()->first();
        $this->assertFalse($draft->guardrail_results['checks']['unverified_specifics']['pass']);
        $this->assertSame(2, collect($this->llm->purposes())->filter(fn ($p) => $p === 'draft')->count(), 'one retry, then stop');
    }

    public function test_emoji_rule_from_brand_voice_is_enforced(): void
    {
        [, $org] = $this->makeOrganization(); // default voice: emoji never
        $this->llm->responses['analysis'] = $this->cleanAnalysis();
        $this->llm->responses['draft'] = $this->drafts('Dank je wel, tot snel! 😊 Team Bistro');

        $review = $this->makeReview($this->makeLocation($org, AutomationMode::Smart), 5, 'Top!');
        $this->process($review);

        $this->assertSame(ReviewState::NeedsHumanWriting, $review->workflow_state);
    }

    public function test_reply_language_follows_the_review(): void
    {
        [, $org] = $this->makeOrganization();
        $location = $this->makeLocation($org, AutomationMode::Manual);
        $cases = ['nl' => 'Het eten was erg lekker en de bediening goed.', 'en' => 'The food was great and the staff were very friendly.', 'de' => 'Das Essen war sehr lecker und wir kommen gut wieder.'];

        foreach ($cases as $expected => $text) {
            $this->llm->requests = [];
            $this->llm->responses['analysis'] = $this->cleanAnalysis(['language' => $expected]);
            $this->process($this->makeReview($location, 4, $text));

            $draft = collect($this->llm->requests)->firstWhere('purpose', 'draft');
            $this->assertStringContainsString("ISO code \"{$expected}\"", $draft->system);
        }

        // A draft in the wrong language fails the guardrail.
        $this->llm->responses['analysis'] = $this->cleanAnalysis(['language' => 'de']);
        $this->llm->responses['draft'] = $this->drafts('Thank you for your review, see you soon!', 'en');
        $review = $this->makeReview($location, 4, 'Sehr gut!');
        $this->process($review);
        $this->assertSame(ReviewState::NeedsHumanWriting, $review->workflow_state);
    }

    public function test_cost_two_calls_per_text_review_and_no_analysis_call_for_rating_only(): void
    {
        [, $org] = $this->makeOrganization();
        $location = $this->makeLocation($org, AutomationMode::Manual);

        $this->process($this->makeReview($location, 5, 'Prima avond.'));
        $this->assertSame(['analysis', 'draft'], $this->llm->purposes());

        $this->llm->requests = [];
        $this->process($this->makeReview($location, 5, null));
        $this->assertSame(['draft'], $this->llm->purposes(), 'rating-only reviews skip the analysis call');
    }

    public function test_regenerate_is_rate_limited(): void
    {
        [$owner, $org] = $this->makeOrganization();
        $review = $this->makeReview($this->makeLocation($org, AutomationMode::Manual), 4);

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($owner)->post(route('reviews.regenerate', $review->id), ['hint' => 'korter'])->assertRedirect();
        }
        $this->actingAs($owner)->post(route('reviews.regenerate', $review->id))->assertStatus(429);
    }

    public function test_offline_demo_ai_is_refused_in_production(): void
    {
        config(['reviewpilot.ai.provider' => 'fake']);
        $this->app->detectEnvironment(fn () => 'production');
        $this->app->forgetInstance(LlmClient::class);
        $this->assertInstanceOf(DisabledLlmClient::class, app(LlmClient::class));

        config(['reviewpilot.ai.provider' => 'anthropic']);
        $this->assertInstanceOf(ClaudeClient::class, app(LlmClient::class));
    }

    public function test_ai_failure_never_blocks_a_review(): void
    {
        [, $org] = $this->makeOrganization();
        $this->llm->responses['analysis'] = $this->cleanAnalysis();
        $this->llm->responses['draft'] = fn () => throw new LlmException('Claude declined this request.');

        $review = $this->makeReview($this->makeLocation($org), 5, 'Top!');
        $this->process($review);

        $this->assertSame(ReviewState::NeedsHumanWriting, $review->workflow_state);
        $this->assertSame(1, AiGeneration::withoutGlobalScopes()->count(), 'only the successful analysis is metered');
    }
}
