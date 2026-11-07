<?php

namespace App\Enums;

enum AutomationMode: string
{
    case Manual = 'manual';
    case Smart = 'smart';
    case Autopilot = 'autopilot';
}
