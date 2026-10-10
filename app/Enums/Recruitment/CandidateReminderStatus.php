<?php

namespace App\Enums\Recruitment;

enum CandidateReminderStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Sent = 'sent';
    case Skipped = 'skipped';
}
