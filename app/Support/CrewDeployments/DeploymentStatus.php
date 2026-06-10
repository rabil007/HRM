<?php

namespace App\Support\CrewDeployments;

use App\Models\EmployeeDeployment;
use Carbon\CarbonImmutable;

final class DeploymentStatus
{
    public const ON_VESSEL = 'on_vessel';

    public const STANDBY = 'standby';

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

        if (self::isStandby($deployment, $today)) {
            return [
                'status' => self::STANDBY,
                'label' => 'Standby',
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

        if (
            $deployment->disembarked_date !== null
            && $deployment->disembarked_date->lte($today)
            && $deployment->travelled_date !== null
        ) {
            return [
                'status' => self::TRAVEL,
                'label' => 'Travel',
                'current_vessel' => null,
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

    public static function standbyDays(EmployeeDeployment $deployment): ?int
    {
        if ($deployment->standby_from === null || $deployment->standby_to === null) {
            return null;
        }

        return (int) $deployment->standby_from->diffInDays($deployment->standby_to) + 1;
    }

    public static function totalDays(EmployeeDeployment $deployment): ?int
    {
        if ($deployment->joined_date === null || $deployment->disembarked_date === null) {
            return null;
        }

        return (int) $deployment->joined_date->diffInDays($deployment->disembarked_date) + 1;
    }

    private static function isOnVessel(EmployeeDeployment $deployment, CarbonImmutable $today): bool
    {
        if ($deployment->joined_date === null || $deployment->joined_date->gt($today)) {
            return false;
        }

        return $deployment->disembarked_date === null || $deployment->disembarked_date->gt($today);
    }

    private static function isStandby(EmployeeDeployment $deployment, CarbonImmutable $today): bool
    {
        if (self::isOnVessel($deployment, $today)) {
            return false;
        }

        if ($deployment->standby_from === null || $deployment->standby_to === null) {
            return false;
        }

        return $today->betweenIncluded($deployment->standby_from, $deployment->standby_to);
    }
}
