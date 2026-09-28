<?php

namespace App\Support\Reports\CrewRelief;

use App\Enums\CrewPhaseCode;

final class CrewReliefAttentionResolver
{
    public const LEVEL_CRITICAL = 'critical';

    public const LEVEL_WARNING = 'warning';

    public const LEVEL_HEALTHY = 'healthy';

    public const LEVEL_NEUTRAL = 'neutral';

    public const REASON_OVERDUE = 'overdue';

    public const REASON_RELIEF_CONFLICT = 'relief_conflict';

    public const REASON_NO_RELIEF = 'no_relief';

    public const REASON_RELIEF_LATE = 'relief_late';

    public const REASON_RELIEF_IN_TRAINING = 'relief_in_training';

    public const REASON_RELIEF_NOT_READY = 'relief_not_ready';

    public const REASON_SIGN_OFF_APPROACHING = 'sign_off_approaching';

    public const REASON_RELIEF_READY = 'relief_ready';

    public const REASON_RELIEF_JOINED = 'relief_joined';

    public const REASON_IN_PROGRESS = 'in_progress';

    /**
     * @param  array{
     *     days_until_signoff: int|null,
     *     has_relief: bool,
     *     relief_conflict: string|null,
     *     relief_joins_late: bool,
     *     days_late: int,
     *     relief_phase_code: CrewPhaseCode|null,
     *     readiness: string,
     * }  $context
     * @return array{
     *     level: string,
     *     badge: string,
     *     reason: string,
     *     urgency_rank: int
     * }
     */
    public function resolve(array $context): array
    {
        $daysUntil = $context['days_until_signoff'];
        $hasRelief = $context['has_relief'];
        $reliefConflict = $context['relief_conflict'];
        $reliefJoinsLate = $context['relief_joins_late'];
        $daysLate = $context['days_late'];
        $reliefPhaseCode = $context['relief_phase_code'];
        $readiness = $context['readiness'];

        // 1. Critical: Sign-off overdue
        if ($daysUntil !== null && $daysUntil < 0) {
            $absDays = abs($daysUntil);
            $badge = $absDays === 1 ? 'Sign-off overdue by 1 day' : "Sign-off overdue by {$absDays} days";

            return [
                'level' => self::LEVEL_CRITICAL,
                'badge' => $badge,
                'reason' => self::REASON_OVERDUE,
                'urgency_rank' => 1,
            ];
        }

        // 2. Critical: Relief conflict
        if ($reliefConflict !== null) {
            return [
                'level' => self::LEVEL_CRITICAL,
                'badge' => 'Relief assignment conflict',
                'reason' => self::REASON_RELIEF_CONFLICT,
                'urgency_rank' => 1,
            ];
        }

        // 3. Critical: No relief assigned and sign-off is approaching (within 7 days)
        if (! $hasRelief && $daysUntil !== null && $daysUntil <= 7) {
            return [
                'level' => self::LEVEL_CRITICAL,
                'badge' => 'No relief assigned',
                'reason' => self::REASON_NO_RELIEF,
                'urgency_rank' => 1,
            ];
        }

        // 4. Warning: Relief joins late
        if ($reliefJoinsLate && $daysLate > 0) {
            $badge = $daysLate === 1 ? 'Relief joins 1 day late' : "Relief joins {$daysLate} days late";

            return [
                'level' => self::LEVEL_WARNING,
                'badge' => $badge,
                'reason' => self::REASON_RELIEF_LATE,
                'urgency_rank' => 4,
            ];
        }

        // 5. Warning: Relief not ready when sign-off is close (within 14 days)
        if ($hasRelief && $daysUntil !== null && $daysUntil <= 14 && $reliefPhaseCode !== null) {
            if ($reliefPhaseCode === CrewPhaseCode::Training) {
                return [
                    'level' => self::LEVEL_WARNING,
                    'badge' => 'Relief still in training',
                    'reason' => self::REASON_RELIEF_IN_TRAINING,
                    'urgency_rank' => 4,
                ];
            }

            if (in_array($reliefPhaseCode, [
                CrewPhaseCode::PreMobilisation,
                CrewPhaseCode::TravelIn,
                CrewPhaseCode::JoinStandby,
            ], true)) {
                return [
                    'level' => self::LEVEL_WARNING,
                    'badge' => 'Relief not ready',
                    'reason' => self::REASON_RELIEF_NOT_READY,
                    'urgency_rank' => 4,
                ];
            }
        }

        // 6. Warning: No relief assigned (sign-off > 7 days)
        if (! $hasRelief) {
            return [
                'level' => self::LEVEL_WARNING,
                'badge' => 'No relief assigned',
                'reason' => self::REASON_NO_RELIEF,
                'urgency_rank' => 3,
            ];
        }

        // 7. Warning: Sign-off approaching (within 7 days) and relief not yet ready/joined
        if ($daysUntil !== null && $daysUntil <= 7 && ! in_array($readiness, ['ready', 'joined'], true)) {
            return [
                'level' => self::LEVEL_WARNING,
                'badge' => 'Sign-off approaching',
                'reason' => self::REASON_SIGN_OFF_APPROACHING,
                'urgency_rank' => 4,
            ];
        }

        // 8. Healthy: Relief joined
        if ($reliefPhaseCode === CrewPhaseCode::OnVessel || $readiness === 'joined') {
            return [
                'level' => self::LEVEL_HEALTHY,
                'badge' => 'Relief joined',
                'reason' => self::REASON_RELIEF_JOINED,
                'urgency_rank' => 5,
            ];
        }

        // 9. Healthy: Relief ready
        if ($readiness === 'ready') {
            return [
                'level' => self::LEVEL_HEALTHY,
                'badge' => 'Relief ready',
                'reason' => self::REASON_RELIEF_READY,
                'urgency_rank' => 5,
            ];
        }

        // 10. Default / Later cases
        return [
            'level' => self::LEVEL_NEUTRAL,
            'badge' => 'Relief in progress',
            'reason' => self::REASON_IN_PROGRESS,
            'urgency_rank' => 6,
        ];
    }
}
