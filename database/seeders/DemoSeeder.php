<?php

namespace Database\Seeders;

use App\Enums\AutomationMode;
use App\Enums\OrganizationRole;
use App\Jobs\ProcessReview;
use App\Models\Location;
use App\Models\LocationFact;
use App\Models\LocationMembership;
use App\Models\OrganizationMembership;
use App\Models\Review;
use App\Models\User;
use App\Services\Organizations\CreateOrganization;
use App\Services\Reviews\ReviewPipeline;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Demo data so the app can be explored without Google or Claude credentials.
 * Log in as demo@reviewpilot.test / password.
 */
class DemoSeeder extends Seeder
{
    private const REVIEWS = [
        [5, 'Super lekker gegeten! De bediening was heel vriendelijk en de burgers waren perfect.', 'Sanne de Vries'],
        [5, 'Gezellige sfeer en snelle service. Komen zeker terug.', 'Mark Jansen'],
        [4, 'Goed eten, wel een beetje duur voor de portie.', 'Lisa Bakker'],
        [2, 'We moesten 45 minuten wachten op ons eten en het was lauw toen het kwam.', 'Pieter Visser'],
        [1, 'Ik ben ziek geworden na het eten van de kip. Echt onacceptabel.', 'Anoniem'],
        [5, 'Amazing fries and the staff were super friendly. Best burger in town!', 'James Miller'],
        [3, 'Food was okay but the toilets were dirty.', 'Emma Clarke'],
        [4, 'Lekker, maar de bestelling was verkeerd. Werd wel snel opgelost.', 'Kevin Smit'],
        [5, null, 'Fatima El Amrani'],
        [2, 'Onvriendelijk personeel bij de kassa en het was erg druk.', 'Tom de Boer'],
        [5, 'Heerlijke milkshakes en vriendelijke bediening!', 'Noor Hendriks'],
        [4, 'Sehr lecker und freundlich, aber etwas laut.', 'Jonas Weber'],
        [1, 'Vies tafeltje en een haar in mijn salade. Nooit meer.', 'Ruud Mulder'],
        [5, 'Thuisbezorgd was snel en het eten nog warm. Top!', 'Eva Peters'],
        [3, 'Prima, niks bijzonders. Prijs-kwaliteit kan beter.', 'Daan Willems'],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            // Demo accounts have a known password and fake reviews: never in production.
            $this->command?->warn('DemoSeeder skipped: not allowed in production.');

            return;
        }

        config(['reviewpilot.ai.provider' => config('reviewpilot.ai.provider') ?: 'fake']);

        $user = User::query()->firstOrCreate(
            ['email' => 'demo@reviewpilot.test'],
            ['name' => 'Demo Owner', 'password' => Hash::make('password'), 'email_verified_at' => now()],
        );

        if ($user->memberships()->exists()) {
            return;
        }

        $org = app(CreateOrganization::class)->handle($user, 'Burgerbar Demo Groep');

        $manager = User::query()->firstOrCreate(
            ['email' => 'manager@reviewpilot.test'],
            ['name' => 'Demo Manager', 'password' => Hash::make('password'), 'email_verified_at' => now()],
        );
        $membership = OrganizationMembership::withoutGlobalScopes()->create([
            'organization_id' => $org->id, 'user_id' => $manager->id, 'role' => OrganizationRole::Member,
        ]);

        $cities = ['Utrecht' => AutomationMode::Smart, 'Amsterdam' => AutomationMode::Manual, 'Rotterdam' => AutomationMode::Smart];
        $locations = [];
        foreach ($cities as $city => $mode) {
            $location = Location::withoutGlobalScopes()->create([
                'organization_id' => $org->id,
                'name' => "Burgerbar {$city}",
                'city' => $city,
                'country' => 'NL',
                'primary_category' => 'Hamburger restaurant',
                'automation_mode' => $mode,
            ]);
            foreach (['opening_hours' => 'Ma–zo 11:00–22:00', 'contact_email' => 'klachten@burgerbar.example', 'reservations' => 'Reserveren kan via de website'] as $key => $value) {
                LocationFact::withoutGlobalScopes()->create(['organization_id' => $org->id, 'location_id' => $location->id, 'key' => $key, 'value' => $value]);
            }
            $locations[] = $location;
        }

        LocationMembership::withoutGlobalScopes()->create([
            'organization_id' => $org->id, 'membership_id' => $membership->id, 'location_id' => $locations[0]->id, 'role' => 'manager',
        ]);

        $i = 0;
        foreach (range(1, 3) as $round) {
            foreach (self::REVIEWS as [$rating, $text, $name]) {
                $location = $locations[$i % count($locations)];
                $created = CarbonImmutable::now()->subHours(($i * 13) % (24 * 75) + 1);
                $review = Review::withoutGlobalScopes()->create([
                    'organization_id' => $org->id,
                    'location_id' => $location->id,
                    'external_id' => 'demo-'.Str::uuid(),
                    'external_name' => 'accounts/demo/locations/demo/reviews/'.$i,
                    'reviewer_display_name' => $name === 'Anoniem' ? null : $name,
                    'reviewer_is_anonymous' => $name === 'Anoniem',
                    'star_rating' => $rating,
                    'comment' => $text,
                    'created_at_source' => $created,
                    'updated_at_source' => $created,
                ]);
                // Old demo reviews: analyse only (as if already handled); recent ones get drafts.
                (new ProcessReview($review->id, draft: $created->gt(now()->subDays(10))))->handle(app(ReviewPipeline::class));
                $i++;
            }
        }
    }
}
