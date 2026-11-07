<?php

namespace App\Models;

use App\Enums\LocationRole;
use App\Enums\OrganizationRole;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationMembership extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['role' => OrganizationRole::class, 'notification_preferences' => 'array'];
    }

    /** Email types a member can switch on or off, with their defaults. */
    public const NOTIFICATION_DEFAULTS = [
        'negative_review' => true,
        'push_alerts' => true,
        'google_reconnect' => true,
        'weekly_digest' => true,
    ];

    /** @return array<string, bool> */
    public function notificationSettings(): array
    {
        $saved = $this->notification_preferences ?? [];

        return collect(self::NOTIFICATION_DEFAULTS)
            ->map(fn (bool $default, string $key) => (bool) ($saved[$key] ?? $default))
            ->all();
    }

    public function wantsEmail(string $type): bool
    {
        return $this->notificationSettings()[$type] ?? true;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function locationMemberships(): HasMany
    {
        return $this->hasMany(LocationMembership::class, 'membership_id');
    }

    /**
     * IDs of locations this member can see. Null means "all locations".
     *
     * @return array<int, string>|null
     */
    public function accessibleLocationIds(): ?array
    {
        if ($this->role->seesAllLocations()) {
            return null;
        }

        return $this->locationMemberships()->pluck('location_id')->all();
    }

    public function canAccessLocation(string $locationId): bool
    {
        $ids = $this->accessibleLocationIds();

        return $ids === null || in_array($locationId, $ids, true);
    }

    public function locationRole(string $locationId): ?LocationRole
    {
        if ($this->role->canManageOrganization()) {
            return LocationRole::Manager;
        }

        if ($this->role === OrganizationRole::Analyst) {
            return LocationRole::Viewer;
        }

        $role = $this->locationMemberships()->where('location_id', $locationId)->first()?->role;

        return $role instanceof LocationRole ? $role : ($role ? LocationRole::from($role) : null);
    }

    public function canApproveFor(string $locationId): bool
    {
        return (bool) $this->locationRole($locationId)?->canApprove();
    }

    public function canDraftFor(string $locationId): bool
    {
        return (bool) $this->locationRole($locationId)?->canDraft();
    }
}
