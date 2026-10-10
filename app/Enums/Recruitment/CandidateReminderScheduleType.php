<?php

namespace App\Enums\Recruitment;

enum CandidateReminderScheduleType: string
{
    case Interview = 'interview';
    case Joining = 'joining';
    case OverdueJoining = 'overdue_joining';
}
