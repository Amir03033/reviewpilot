<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Jobs\ExportOrganizationData;
use App\Jobs\ProcessReview;
use App\Models\DataExport;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Notifications\DataExportReady;
use App\Notifications\NegativeReviewReceived;
use App\Services\Reviews\ReviewPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;
use ZipArchive;

class LaunchFeaturesTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['reviewpilot.ai.provider' => 'fake']);
        Notification::fake();
    }

    // --- Notification settings ---

    public function test_members_can_switch_off_negative_review_emails(): void
    {
        [$owner, $org] = $this->makeOrganization();
        $membership = $owner->membershipFor($org);

        $this->actingAs($owner)->get('/settings/notifications')->assertOk()
            ->assertInertia(fn ($page) => $page->where('memberships.0.settings.negative_review', true));

        $this->actingAs($owner)->put('/settings/notifications', [
            'membership_id' => $membership->id,
            'settings' => ['negative_review' => false, 'google_reconnect' => true, 'weekly_digest' => true, 'unknown' => true],
        ])->assertRedirect();

        $membership->refresh();
        $this->assertFalse($membership->wantsEmail('negative_review'));
        $this->assertArrayNotHasKey('unknown', $membership->notification_preferences);

        $review = $this->makeReview($this->makeLocation($org), 1, 'Koud eten en lang gewacht.');
        (new ProcessReview($review->id))->handle(app(ReviewPipeline::class));

        Notification::assertNotSentTo($owner, NegativeReviewReceived::class);
    }

    public function test_you_cannot_change_someone_elses_notification_settings(): void
    {
        [$owner, $org] = $this->makeOrganization();
        [$other] = $this->makeOrganization(name: 'Other');

        $this->actingAs($other)->put('/settings/notifications', [
            'membership_id' => $owner->membershipFor($org)->id,
            'settings' => ['negative_review' => false],
        ])->assertNotFound();
    }

    // --- Onboarding checklist ---

    public function test_checklist_ticks_off_steps_and_can_be_hidden(): void
    {
        [$owner, $org] = $this->makeOrganization();

        $this->actingAs($owner)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('checklist.dismissed', false)
            ->where('checklist.steps.0.key', 'connect_google')
            ->where('checklist.steps.0.done', false));

        $this->makeLocation($org);
        $this->actingAs($owner)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('checklist.steps.0.done', true)
            ->where('checklist.steps.1.done', true));

        $this->actingAs($owner)->post('/dashboard/checklist/dismiss')->assertRedirect();
        $this->actingAs($owner)->get('/dashboard')->assertInertia(fn ($page) => $page->where('checklist.dismissed', true));
    }

    public function test_ai_usage_is_shared_for_the_sidebar_meter(): void
    {
        [$owner] = $this->makeOrganization();

        $this->actingAs($owner)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('tenant.aiUsage.used', 0)
            ->where('tenant.aiUsage.allowance', 400)); // trial = Pro: 400 per location
    }

    // --- Data export ---

    public function test_export_contains_the_organisations_data_only_and_is_emailed(): void
    {
        Storage::fake('local');
        [$owner, $org] = $this->makeOrganization();
        $this->makeReview($this->makeLocation($org), 5, '=HYPERLINK("http://evil") Heerlijk gegeten');
        [, $other] = $this->makeOrganization(name: 'Other');
        $this->makeReview($this->makeLocation($other), 4, 'Andere organisatie');

        Queue::fake();
        $this->actingAs($owner)->post('/organization/export')->assertRedirect();
        Queue::assertPushed(ExportOrganizationData::class);

        $export = DataExport::withoutGlobalScopes()->sole();
        (new ExportOrganizationData($export->id))->handle();
        $export->refresh();

        $this->assertSame('ready', $export->status);
        Notification::assertSentTo($owner, DataExportReady::class);

        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path($export->path));
        $reviews = $zip->getFromName('reviews.csv');
        $this->assertStringContainsString("'=HYPERLINK", $reviews, 'formula injection is neutralised');
        $this->assertStringNotContainsString('Andere organisatie', $reviews);
        $this->assertNotFalse($zip->getFromName('audit_log.csv'));

        $url = URL::temporarySignedRoute('organization.export.download', $export->expires_at, ['export' => $export->id]);
        $this->actingAs($owner)->get($url)->assertOk()->assertDownload();
        $this->actingAs($owner)->get(route('organization.export.download', $export->id))->assertForbidden(); // unsigned
    }

    public function test_only_managers_can_export(): void
    {
        [$owner, $org] = $this->makeOrganization();
        $analyst = User::factory()->create();
        OrganizationMembership::withoutGlobalScopes()->create(['organization_id' => $org->id, 'user_id' => $analyst->id, 'role' => OrganizationRole::Analyst]);
        $analyst->switchOrganization($org);

        $this->actingAs($analyst)->post('/organization/export')->assertForbidden();
    }

    public function test_expired_exports_are_pruned(): void
    {
        Storage::fake('local');
        [, $org] = $this->makeOrganization();
        Storage::disk('local')->put('exports/x.zip', 'zip');
        DataExport::withoutGlobalScopes()->create(['organization_id' => $org->id, 'status' => 'ready', 'path' => 'exports/x.zip', 'expires_at' => now()->subDay()]);

        $this->artisan('reviewpilot:prune-exports')->assertSuccessful();

        $this->assertSame(0, DataExport::withoutGlobalScopes()->count());
        Storage::disk('local')->assertMissing('exports/x.zip');
    }
}
