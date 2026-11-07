<?php

namespace App\Enums;

enum LocationRole: string
{
    case Manager = 'manager';
    case Contributor = 'contributor';
    case Viewer = 'viewer';

    public function canApprove(): bool
    {
        return $this === self::Manager;
    }

    public function canDraft(): bool
    {
        return $this !== self::Viewer;
    }
}
