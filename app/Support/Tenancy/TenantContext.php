<?php

namespace App\Support\Tenancy;

use App\Models\Organization;
use App\Models\OrganizationMembership;

/**
 * Holds the organisation the current request or job operates on.
 *
 * Set by the ResolveOrganization middleware for web requests and by jobs via
 * TenantContext::run(). Models using BelongsToOrganization are automatically
 * scoped to the active organisation while a context is set.
 */
class TenantContext
{
    private ?Organization $organization = null;

    private ?OrganizationMembership $membership = null;

    public function set(Organization $organization, ?OrganizationMembership $membership = null): void
    {
        $this->organization = $organization;
        $this->membership = $membership;
    }

    public function clear(): void
    {
        $this->organization = null;
        $this->membership = null;
    }

    public function organization(): ?Organization
    {
        return $this->organization;
    }

    public function organizationId(): ?string
    {
        return $this->organization?->getKey();
    }

    public function membership(): ?OrganizationMembership
    {
        return $this->membership;
    }

    public function has(): bool
    {
        return $this->organization !== null;
    }

    /**
     * Run a callback within the given organisation, restoring the previous context after.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(Organization $organization, callable $callback): mixed
    {
        $previousOrg = $this->organization;
        $previousMembership = $this->membership;
        $this->set($organization);

        try {
            return $callback();
        } finally {
            $this->organization = $previousOrg;
            $this->membership = $previousMembership;
        }
    }
}
