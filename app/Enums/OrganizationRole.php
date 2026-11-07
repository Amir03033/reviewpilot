<?php

namespace App\Enums;

enum OrganizationRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Analyst = 'analyst';
    case Billing = 'billing';
    case Member = 'member';

    /** Roles that implicitly have access to every location in the organisation. */
    public function seesAllLocations(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::Analyst], true);
    }

    public function canManageOrganization(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    public function canManageBilling(): bool
    {
        return in_array($this, [self::Owner, self::Billing], true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
