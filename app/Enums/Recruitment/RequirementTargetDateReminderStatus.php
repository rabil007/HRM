<?php

namespace App\Enums\Recruitment;

enum RequirementTargetDateReminderStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Sent = 'sent';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
