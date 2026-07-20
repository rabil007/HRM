<?php

namespace App\Enums;

enum CrewTimelineWarningCode: string
{
    case MissingActualStart = 'missing_actual_start';
    case MissingActualEnd = 'missing_actual_end';
    case OverlappingPhases = 'overlapping_phases';
    case TimelineGap = 'timeline_gap';
    case PendingMovementCorrection = 'pending_movement_correction';
    case MonthlyContractNotSupported = 'monthly_contract_not_supported';
    case NoActiveCrewContract = 'no_active_crew_contract';
    case FutureActualDate = 'future_actual_date';
    case CrossCompanyReference = 'cross_company_reference';
    case InvalidPhaseRange = 'invalid_phase_range';
    case TravelInExcluded = 'travel_in_excluded';

    public function isBlocking(): bool
    {
        return match ($this) {
            self::MissingActualStart,
            self::MissingActualEnd,
            self::OverlappingPhases,
            self::PendingMovementCorrection,
            self::NoActiveCrewContract,
            self::CrossCompanyReference,
            self::InvalidPhaseRange => true,
            self::TimelineGap,
            self::MonthlyContractNotSupported,
            self::FutureActualDate,
            self::TravelInExcluded => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::MissingActualStart => 'Missing actual start',
            self::MissingActualEnd => 'Missing actual end',
            self::OverlappingPhases => 'Overlapping phases',
            self::TimelineGap => 'Timeline gap',
            self::PendingMovementCorrection => 'Pending movement correction',
            self::MonthlyContractNotSupported => 'Monthly contract not supported',
            self::NoActiveCrewContract => 'No active crew contract',
            self::FutureActualDate => 'Future actual date',
            self::CrossCompanyReference => 'Cross-company reference',
            self::InvalidPhaseRange => 'Invalid phase range',
            self::TravelInExcluded => 'Travel In excluded',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
