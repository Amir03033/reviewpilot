<?php

namespace App\Enums;

enum ConnectionStatus: string
{
    case Active = 'active';
    case NeedsReauth = 'needs_reauth';
    case Revoked = 'revoked';
}
