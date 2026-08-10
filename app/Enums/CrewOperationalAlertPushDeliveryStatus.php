<?php

namespace App\Enums;

enum CrewOperationalAlertPushDeliveryStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
}
