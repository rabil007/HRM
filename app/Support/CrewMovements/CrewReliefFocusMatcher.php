<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewReliefRisk;
use App\Enums\CrewReliefStatus;

final class CrewReliefFocusMatcher
{
    /**
     * @return list<string>
     */
    public static function focusValues(): array
    {
        return [
            'needs_relief',
            'critical',
            'not_ready',
            'signoff_7',
            'signoff_14',
            'ready',
            'overdue',
            'missing_planned_signoff',
        ];
    }

    public static function matches(CrewReliefReadinessResult $relief, string $focus): bool
    {
        if ($focus === '') {
            return true;
        }

        $days = $relief->daysUntilSignoff;
        $missingSignoff = $relief->sourcePlannedSignoffDate === null;

        return match ($focus) {
            'needs_relief' => $relief->status === CrewReliefStatus::NoRelief,
            'critical' => $relief->risk === CrewReliefRisk::Critical,
            'not_ready' => in_array($relief->status, [
                CrewReliefStatus::ReliefPlanned,
                CrewReliefStatus::AssignmentCreated,
                CrewReliefStatus::Mobilising,
            ], true),
            'signoff_7' => $days !== null && $days >= 0 && $days <= 7,
            'signoff_14' => $days !== null && $days >= 0 && $days <= 14,
            'ready' => $relief->status->isReadyOrOnboard(),
            'overdue' => $days !== null && $days < 0,
            'missing_planned_signoff' => $missingSignoff,
            default => true,
        };
    }
}
