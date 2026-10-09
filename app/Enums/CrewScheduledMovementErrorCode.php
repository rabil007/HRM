<?php

namespace App\Enums;

enum CrewScheduledMovementErrorCode: string
{
    case AlreadyResolved = 'already_resolved';
    case Cancelled = 'cancelled';
    case PhaseChanged = 'phase_changed';
    case AssignmentStateChanged = 'assignment_state_changed';
    case ActionNoLongerEligible = 'action_no_longer_eligible';
    case ChronologyInvalid = 'chronology_invalid';
    case ConflictDetected = 'conflict_detected';
    case AccommodationInvalid = 'accommodation_invalid';
    case LatenessExceeded = 'lateness_exceeded';
    case MovementFailed = 'movement_failed';
    case DuplicateActiveSchedule = 'duplicate_active_schedule';
    case NotSchedulable = 'not_schedulable';
    case ScheduledAtNotFuture = 'scheduled_at_not_future';
    case StaleProcessing = 'stale_processing';

    public function label(): string
    {
        return match ($this) {
            self::AlreadyResolved => 'Already resolved',
            self::Cancelled => 'Cancelled',
            self::PhaseChanged => 'Current phase changed',
            self::AssignmentStateChanged => 'Assignment state changed',
            self::ActionNoLongerEligible => 'Action no longer eligible',
            self::ChronologyInvalid => 'Chronology invalid',
            self::ConflictDetected => 'Conflict detected',
            self::AccommodationInvalid => 'Accommodation invalid',
            self::LatenessExceeded => 'Processing delayed beyond tolerance',
            self::MovementFailed => 'Movement execution failed',
            self::DuplicateActiveSchedule => 'Another schedule is already pending',
            self::NotSchedulable => 'Action cannot be scheduled',
            self::ScheduledAtNotFuture => 'Scheduled time must be in the future',
            self::StaleProcessing => 'Stale processing recovered',
        };
    }
}
