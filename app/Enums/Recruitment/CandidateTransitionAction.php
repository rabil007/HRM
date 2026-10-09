<?php

namespace App\Enums\Recruitment;

enum CandidateTransitionAction: string
{
    case Created = 'created';
    case MovedToScreening = 'moved_to_screening';
    case MovedToInterview = 'moved_to_interview';
    case Rejected = 'rejected';
    case Selected = 'selected';
    case UndoSelected = 'undo_selected';
    case ReopenRejected = 'reopen_rejected';
    case InterviewUpdated = 'interview_updated';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Created',
            self::MovedToScreening => 'Moved to Screening',
            self::MovedToInterview => 'Moved to Interview',
            self::Rejected => 'Rejected',
            self::Selected => 'Selected',
            self::UndoSelected => 'Undo Selected',
            self::ReopenRejected => 'Reopened',
            self::InterviewUpdated => 'Interview updated',
        };
    }
}
