<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Support\Tenancy\OrganizationScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned model: scoped to the active organisation and stamped with
 * organization_id on create.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function ($model) {
            if (empty($model->organization_id)) {
                $model->organization_id = app(TenantContext::class)->organizationId();
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
