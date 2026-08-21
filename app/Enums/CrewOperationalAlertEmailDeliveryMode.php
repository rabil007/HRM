<?php

namespace App\Enums;

enum CrewOperationalAlertEmailDeliveryMode: string
{
    case Scheduled = 'scheduled';
    case Immediate = 'immediate';
}
