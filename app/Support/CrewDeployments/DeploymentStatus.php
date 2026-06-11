<?php

namespace App\Support\CrewDeployments;

use App\Models\EmployeeDeployment;
use Carbon\CarbonImmutable;

final class DeploymentStatus
{
    public const ON_VESSEL = 'on_vessel';

    public const JOIN_STANDBY = 'join_standby';

    /** @deprecated Use JOIN_STANDBY */
    public const STANDBY = self::JOIN_STANDBY;

    public const LEAVE_STANDBY = 'leave_standby';

    public const AWAITING_JOIN = 'awaiting_join';

    public const TRAVEL = 'travel';

    public const DISEMBARKED = 'disembarked';

    public const UNKNOWN = 'unknown';

    /**
     * @return array{status: string, label: string, current_vessel: string|null}
     */
    public static function resolve(EmployeeDeployment $deployment, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();

        if (self::isOnVessel($deployment, $today)) {
            return [
                'status' => self::ON_VESSEL,
                'label' => 'On '.($deployment->vessel_name ?: 'vessel'),
                'current_vessel' => $deployment->vessel_name,
            ];
        }

        if (self::isJoinStandby($deployment, $today)) {
            return [
                'status' => self::JOIN_STANDBY,
                'label' => 'Join standby',
                'current_vessel' => null,
            ];
        }

        if (self::isLeaveStandby($deployment, $today)) {
            return [
                'status' => self::LEAVE_STANDBY,
                'label' => 'Leave standby',
                'current_vessel' => null,
            ];
        }

        if (
            $deployment->disembarked_date !== null
            && $deployment->disembarked_date->lte($today)
            && $deployment->travelled_date !== null
        ) {
            return [
                'status' => self::TRAVEL,
                'label' => 'Travelled',
                'current_vessel' => null,
            ];
        }

        if ($deployment->arrived_date !== null && $deployment->joined_date === null) {
            return [
                'status' => self::AWAITING_JOIN,
                'label' => 'Awaiting join',
                'current_vessel' => $deployment->vessel_name,
            ];
        }

        if ($deployment->disembarked_date !== null && $deployment->disembarked_date->lte($today)) {
            return [
                'status' => self::DISEMBARKED,
                'label' => 'Disembarked',
                'current_vessel' => null,
            ];
        }

        return [
            'status' => self::UNKNOWN,
            'label' => 'Needs update',
            'current_vessel' => $deployment->vessel_name,
        ];
    }

    public static function joinStandbyDays(EmployeeDeployment $deployment): ?int
    {
        return self::daysBetween($deployment->join_standby_from, $deployment->join_standby_to);
    }

    public static function leaveStandbyDays(EmployeeDeployment $deployment): ?int
    {
        return self::daysBetween($deployment->leave_standby_from, $deployment->leave_standby_to);
    }

    /** @deprecated Use joinStandbyDays() */
    public static function standbyDays(EmployeeDeployment $deployment): ?int
    {
        return self::joinStandbyDays($deployment);
    }

    public static function vesselDays(EmployeeDeployment $deployment): ?int
    {
        return self::daysBetween($deployment->joined_date, $deployment->disembarked_date);
    }

    /** @deprecated Use vesselDays() */
    public static function totalDays(EmployeeDeployment $deployment): ?int
    {
        return self::vesselDays($deployment);
    }

    private static function daysBetween(?CarbonImmutable $from, ?CarbonImmutable $to): ?int
    {
        if ($from === null || $to === null) {
            return null;
        }

        return (int) $from->diffInDays($to) + 1;
    }

    private static function isOnVessel(EmployeeDeployment $deployment, CarbonImmutable $today): bool
    {
        if ($deployment->joined_date === null || $deployment->joined_date->gt($today)) {
            return false;
        }

        return $deployment->disembarked_date === null || $deployment->disembarked_date->gt($today);
    }

    private static function isJoinStandby(EmployeeDeployment $deployment, CarbonImmutable $today): bool
    {
        if (self::isOnVessel($deployment, $today)) {
            return false;
        }

        if ($deployment->join_standby_from === null || $deployment->join_standby_to === null) {
            return false;
        }

        if ($deployment->joined_date !== null && $deployment->joined_date->lte($today)) {
            return false;
        }

        return $today->betweenIncluded($deployment->join_standby_from, $deployment->join_standby_to);
    }

    private static function isLeaveStandby(EmployeeDeployment $deployment, CarbonImmutable $today): bool
    {
        if ($deployment->disembarked_date === null || $deployment->disembarked_date->gt($today)) {
            return false;
        }

        if ($deployment->leave_standby_from === null || $deployment->leave_standby_to === null) {
            return false;
        }

        if ($deployment->travelled_date !== null && $deployment->travelled_date->lte($today)) {
            return false;
        }

        return $today->betweenIncluded($deployment->leave_standby_from, $deployment->leave_standby_to);
    }
}
