<?php

namespace App\Enums;

enum ReplyState: string
{
    case Scheduled = 'scheduled';
    case Publishing = 'publishing';
    case Published = 'published';
    case Failed = 'failed';
    case Deleted = 'deleted';
    case Cancelled = 'cancelled';
}
