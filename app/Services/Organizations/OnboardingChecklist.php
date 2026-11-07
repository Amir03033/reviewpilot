<?php

namespace App\Services\Organizations;

use App\Enums\ReplyState;
use App\Models\BrandVoiceVersion;
use App\Models\GoogleConnection;
use App\Models\Invitation;
use App\Models\Location;
use App\Models\LocationFact;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ReviewReply;
use App\Models\Subscription;

/**
 * "Getting started" steps shown on the dashboard until they are done or dismissed.
 * Every step is derived from real data, so it ticks itself off.
 */
class OnboardingChecklist
{
    /** @return array{dismissed: bool, steps: array<int, array{key: string, title: string, description: string, href: string, done: bool}>} */
    public function for(Organization $organization): array
    {
        $org = $organization->id;
        $q = fn (string $model) => $model::withoutGlobalScopes()->where('organization_id', $org);

        $steps = [
            ['connect_google', 'Connect Google', 'Link the Google account that manages your Business Profile.', '/integrations',
                $q(GoogleConnection::class)->exists()],
            ['import_location', 'Import your locations', 'Choose which Google locations ReviewPilot manages.', '/integrations',
                $q(Location::class)->exists()],
            ['brand_voice', 'Set your brand voice', 'Tone, sign-off and phrases you do or do not use.', '/voice',
                $q(BrandVoiceVersion::class)->count() > 1],
            ['facts', 'Add facts about your business', 'Opening hours, contact details, reservations: replies only use facts you provide.', '/locations',
                $q(LocationFact::class)->exists()],
            ['invite_team', 'Invite your team', 'Managers can approve replies for their own location.', '/team',
                $q(OrganizationMembership::class)->count() > 1 || $q(Invitation::class)->exists()],
            ['first_reply', 'Publish your first reply', 'Approve a suggested reply in the approval queue.', '/approvals',
                $q(ReviewReply::class)->where('state', ReplyState::Published->value)->exists()],
            ['choose_plan', 'Choose a plan', 'Keep ReviewPilot running after your trial.', '/billing',
                $q(Subscription::class)->whereIn('status', ['active', 'past_due'])->exists()],
        ];

        return [
            'dismissed' => (bool) data_get($organization->settings, 'onboarding_dismissed', false),
            'steps' => array_map(fn ($s) => [
                'key' => $s[0], 'title' => $s[1], 'description' => $s[2], 'href' => $s[3], 'done' => $s[4],
            ], $steps),
        ];
    }
}
