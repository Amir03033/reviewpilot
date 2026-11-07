<?php

namespace Tests\Feature\Production;

use App\Models\GoogleConnection;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\GoogleReconnectRequired;
use App\Notifications\InvitationSent;
use App\Notifications\NegativeReviewReceived;
use App\Notifications\WeeklyDigest;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Finder\Finder;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class InfrastructureTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    /** Catches fatal class-composition errors (like a trait property clash) before production does. */
    public function test_every_application_class_loads(): void
    {
        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            $this->assertTrue(class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class), "{$class} failed to load");
        }
    }

    public function test_every_notification_renders(): void
    {
        [$owner, $org] = $this->makeOrganization();
        $location = $this->makeLocation($org);
        $review = $this->makeReview($location, 1, 'Koud');
        $connection = GoogleConnection::withoutGlobalScopes()->first();
        $invitation = Invitation::withoutGlobalScopes()->create(['organization_id' => $org->id, 'email' => 'a@b.c', 'role' => 'member', 'token_hash' => 'h', 'expires_at' => now()->addDay()]);

        foreach ([
            new NegativeReviewReceived($review),
            new GoogleReconnectRequired($connection),
            new InvitationSent($invitation, 'token'),
            new WeeklyDigest($org, ['reviews' => 3, 'average' => 4.2, 'replied' => 2, 'pending' => 1, 'negative' => 1, 'top_topics' => ['Service']]),
        ] as $notification) {
            $html = (string) $notification->toMail($owner)->render();
            $this->assertNotEmpty($html, get_class($notification));
        }
    }

    public function test_demo_data_is_never_seeded_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->seed(DemoSeeder::class);

        $this->assertSame(0, User::where('email', 'demo@reviewpilot.test')->count());
    }

    public function test_doctor_fails_on_an_unsafe_production_configuration(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['reviewpilot.ai.provider' => 'fake', 'app.debug' => true, 'mollie.key' => 'test_x']);

        $this->assertSame(1, Artisan::call('reviewpilot:doctor'));
        $output = Artisan::output();
        $this->assertStringContainsString('AI_PROVIDER=anthropic', $output);
        $this->assertStringContainsString('APP_DEBUG is false', $output);
    }

    public function test_scheduler_has_all_production_tasks(): void
    {
        Artisan::call('schedule:list');
        $out = Artisan::output();

        foreach (['reviewpilot:publish-due', 'reviewpilot:poll', 'reviewpilot:poll --mode=reconcile', 'reviewpilot:prune', 'reviewpilot:purge-organizations', 'reviewpilot:weekly-digest', 'reviewpilot:billing-reconcile'] as $command) {
            $this->assertStringContainsString($command, $out);
        }
    }

    public function test_weekly_digest_runs_for_every_organization(): void
    {
        Notification::fake();
        [$owner, $org] = $this->makeOrganization();
        $this->makeReview($this->makeLocation($org), 5);

        Artisan::call('reviewpilot:weekly-digest');

        Notification::assertSentTo($owner, WeeklyDigest::class);
    }

    public function test_deleted_organizations_are_purged_after_the_grace_period(): void
    {
        [, $org] = $this->makeOrganization();
        $this->makeReview($this->makeLocation($org), 5);
        $org->delete();

        Artisan::call('reviewpilot:purge-organizations');
        $this->assertNotNull(Organization::withTrashed()->find($org->id), 'kept during grace period');

        $this->travel(31)->days();
        Artisan::call('reviewpilot:purge-organizations');
        $this->assertNull(Organization::withTrashed()->find($org->id));
        $this->assertDatabaseCount('reviews', 0);
        $this->assertDatabaseCount('google_connections', 0);
    }

    public function test_inbox_search_is_case_insensitive(): void
    {
        [$owner, $org] = $this->makeOrganization();
        $this->makeReview($this->makeLocation($org), 2, 'Het eten was KOUD en de Friet slap.');

        $this->actingAs($owner)->get(route('reviews.index', ['status' => 'all', 'q' => 'friet']))
            ->assertInertia(fn ($p) => $p->where('reviews.total', 1));
        $this->actingAs($owner)->get(route('reviews.index', ['status' => 'all', 'q' => '100%']))
            ->assertInertia(fn ($p) => $p->where('reviews.total', 0));
    }

    public function test_publish_feedback_tells_the_truth(): void
    {
        Http::fake(['mybusiness.googleapis.com/*' => Http::response(['comment' => 'ok'])]);
        [$owner, $org] = $this->makeOrganization();
        $connected = $this->makeReview($this->makeLocation($org), 5);
        $notConnected = $this->makeReview($this->makeLocation($org, connected: false), 5);

        $this->actingAs($owner)->post(route('reviews.reply', $connected->id), ['text' => 'Dank je!']);
        $this->assertStringContainsString('Reply published on Google.', json_encode(session()->all()));

        $this->actingAs($owner)->post(route('reviews.reply', $notConnected->id), ['text' => 'Dank je!']);
        $flash = json_encode(session()->all());
        $this->assertStringContainsString('Not published', $flash);
        $this->assertStringNotContainsString('Reply published on Google', $flash);
    }
}
