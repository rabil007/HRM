<?php

namespace App\Enums;

enum CrewOperationalAlertSeverity: string
{
    case Critical = 'critical';
    case Warning = 'warning';
    case Info = 'info';
}
