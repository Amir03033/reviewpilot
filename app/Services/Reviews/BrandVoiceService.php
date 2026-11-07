<?php

namespace App\Services\Reviews;

use App\Models\BrandVoice;
use App\Models\BrandVoiceVersion;
use App\Models\Location;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Brand voice ("restaurant personality") management and resolution.
 * Organisation voice is the default; a location voice overrides it field by field.
 */
class BrandVoiceService
{
    public const GENERIC_CLICHES = [
        'sorry for the inconvenience',
        'we value your feedback',
        'your feedback is important to us',
        'excuses voor het ongemak',
        'wij waarderen uw feedback',
    ];

    public function __construct(private AuditLogger $audit) {}

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'settings' => [
                'formality' => 3,
                'warmth' => 4,
                'humor' => 'light',
                'emoji' => 'never',
                'length' => 'standard',
                'sign_off' => null,
                'language_mode' => 'auto',
                'fixed_language' => null,
                'compensation_policy' => 'invite_contact',
            ],
            'preferred_phrases' => [],
            'forbidden_phrases' => self::GENERIC_CLICHES,
            'guidelines' => null,
            'special_instructions' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveVersion(BrandVoice $voice, array $data, ?User $author = null): BrandVoiceVersion
    {
        return DB::transaction(function () use ($voice, $data, $author) {
            $next = (int) BrandVoiceVersion::withoutGlobalScopes()->where('brand_voice_id', $voice->id)->max('version') + 1;

            $version = BrandVoiceVersion::withoutGlobalScopes()->create([
                'organization_id' => $voice->organization_id,
                'brand_voice_id' => $voice->id,
                'version' => $next,
                'settings' => $data['settings'] ?? [],
                'preferred_phrases' => array_values(array_filter($data['preferred_phrases'] ?? [])),
                'forbidden_phrases' => array_values(array_filter($data['forbidden_phrases'] ?? [])),
                'guidelines' => $data['guidelines'] ?? null,
                'special_instructions' => $data['special_instructions'] ?? null,
                'created_by' => $author?->id,
            ]);

            $voice->forceFill(['current_version_id' => $version->id])->save();

            if ($next > 1) {
                $this->audit->log('voice.updated', $voice, ['version' => $next]);
            }

            return $version;
        });
    }

    public function organizationVoice(string $organizationId): ?BrandVoice
    {
        return BrandVoice::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('scope', 'organization')
            ->first();
    }

    public function locationVoice(Location $location, bool $create = false): ?BrandVoice
    {
        $voice = BrandVoice::withoutGlobalScopes()->where('location_id', $location->id)->first();

        if (! $voice && $create) {
            $voice = BrandVoice::withoutGlobalScopes()->create([
                'organization_id' => $location->organization_id,
                'scope' => 'location',
                'location_id' => $location->id,
            ]);
        }

        return $voice;
    }

    /**
     * Resolve the effective voice for a location.
     *
     * @return array{settings: array<string, mixed>, preferred_phrases: array<int, string>, forbidden_phrases: array<int, string>, guidelines: ?string, special_instructions: ?string, version_id: ?string}
     */
    public function resolve(Location $location): array
    {
        $resolved = self::defaults();
        $resolved['version_id'] = null;

        $orgVersion = $this->organizationVoice($location->organization_id)?->currentVersion;
        if ($orgVersion) {
            $resolved = $this->merge($resolved, $orgVersion, replaceForbidden: true);
            $resolved['version_id'] = $orgVersion->id;
        }

        $locationVersion = $this->locationVoice($location)?->currentVersion;
        if ($locationVersion) {
            $resolved = $this->merge($resolved, $locationVersion, replaceForbidden: false);
            $resolved['version_id'] = $locationVersion->id;
        }

        $resolved['settings']['sign_off'] ??= 'Team '.$location->name;

        return $resolved;
    }

    private function merge(array $base, BrandVoiceVersion $version, bool $replaceForbidden): array
    {
        $settings = array_filter($version->settings ?? [], fn ($v) => $v !== null && $v !== '');
        $base['settings'] = array_merge($base['settings'], $settings);

        if (! empty($version->preferred_phrases)) {
            $base['preferred_phrases'] = $version->preferred_phrases;
        }

        // Forbidden phrases are additive for location overrides: a location can never un-forbid.
        $base['forbidden_phrases'] = $replaceForbidden
            ? ($version->forbidden_phrases ?? [])
            : array_values(array_unique(array_merge($base['forbidden_phrases'], $version->forbidden_phrases ?? [])));

        foreach (['guidelines', 'special_instructions'] as $field) {
            if (filled($version->{$field})) {
                $base[$field] = trim(($replaceForbidden ? '' : ($base[$field] ?? ''))."\n".$version->{$field});
            }
        }

        return $base;
    }
}
