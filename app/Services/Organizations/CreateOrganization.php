<?php

namespace App\Services\Organizations;

use App\Enums\OrganizationRole;
use App\Models\BrandVoice;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Reviews\BrandVoiceService;
use App\Support\Sectors;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateOrganization
{
    public function __construct(private AuditLogger $audit, private BrandVoiceService $voices) {}

    public function handle(User $owner, string $name, string $country = 'NL', string $sector = Sectors::DEFAULT): Organization
    {
        return DB::transaction(function () use ($owner, $name, $country, $sector) {
            $organization = Organization::create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'country' => strtoupper($country),
                'sector' => in_array($sector, Sectors::keys(), true) ? $sector : Sectors::DEFAULT,
                'trial_ends_at' => now()->addDays(config('reviewpilot.trial_days')),
                'billing_email' => $owner->email,
            ]);

            OrganizationMembership::withoutGlobalScopes()->create([
                'organization_id' => $organization->id,
                'user_id' => $owner->id,
                'role' => OrganizationRole::Owner,
            ]);

            $voice = BrandVoice::withoutGlobalScopes()->create([
                'organization_id' => $organization->id,
                'scope' => 'organization',
            ]);
            $this->voices->saveVersion($voice, BrandVoiceService::defaults(), $owner);

            $owner->switchOrganization($organization);

            $this->audit->log('organization.created', $organization, ['name' => $name], organizationId: $organization->id);

            return $organization;
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'org';
        $slug = $base;
        $i = 1;

        while (Organization::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }
}
