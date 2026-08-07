<?php

namespace App\Enums;

enum CrewOperationalAlertEmailDeliveryStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
}
