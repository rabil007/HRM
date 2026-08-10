<?php

namespace App\Enums;

enum CrewOperationalAlertStatus: string
{
    case Active = 'active';
    case Resolved = 'resolved';
}
