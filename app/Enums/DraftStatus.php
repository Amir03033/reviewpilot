<?php

namespace App\Enums;

enum DraftStatus: string
{
    case Proposed = 'proposed';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Superseded = 'superseded';
    case Stale = 'stale';
}
