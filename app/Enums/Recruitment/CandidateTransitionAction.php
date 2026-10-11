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
    case OfferPrepared = 'offer_prepared';
    case OfferUpdated = 'offer_updated';
    case OfferSent = 'offer_sent';
    case OfferAccepted = 'offer_accepted';
    case OfferRejected = 'offer_rejected';
    case OfferRevised = 'offer_revised';
    case Joined = 'joined';
    case JoiningReadinessUpdated = 'joining_readiness_updated';
    case JoiningCorrected = 'joining_corrected';
    case EmployeeConverted = 'employee_converted';
    case EmployeeLinked = 'employee_linked';

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
            self::OfferPrepared => 'Offer prepared',
            self::OfferUpdated => 'Offer updated',
            self::OfferSent => 'Offer marked sent',
            self::OfferAccepted => 'Offer accepted',
            self::OfferRejected => 'Offer rejected',
            self::OfferRevised => 'Offer revised',
            self::Joined => 'Confirmed joined',
            self::JoiningReadinessUpdated => 'Joining readiness updated',
            self::JoiningCorrected => 'Joining corrected',
            self::EmployeeConverted => 'Converted to employee',
            self::EmployeeLinked => 'Linked to existing employee',
        };
    }
}
