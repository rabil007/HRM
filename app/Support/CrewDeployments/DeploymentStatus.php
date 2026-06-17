<?php

namespace App\Support\CrewDeployments;

use App\Models\EmployeeDeployment;
use Carbon\CarbonImmutable;

final class DeploymentStatus
{
    private const DUE_SOON_WINDOW_DAYS = 2;

    public const ON_VESSEL = 'on_vessel';

    public const JOIN_STANDBY = 'join_standby';

    /** @deprecated Use JOIN_STANDBY */
    public const STANDBY = self::JOIN_STANDBY;

    public const LEAVE_STANDBY = 'leave_standby';

    public const ARRIVED = 'arrived';

    /** @deprecated Use ARRIVED */
    public const AWAITING_JOIN = self::ARRIVED;

    public const TRAVEL = 'travel';

    public const IN_HOME = 'in_home';

    public const DISEMBARKED = 'disembarked';

    public const UNKNOWN = 'unknown';

    private static function vesselDisplayName(EmployeeDeployment $deployment): ?string
    {
        return $deployment->vessel?->name;
    }

    /**
     * @return array{status: string, label: string, current_vessel: string|null}
     */
    public static function resolve(EmployeeDeployment $deployment, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $vesselName = self::vesselDisplayName($deployment);

        if (self::isOnVessel($deployment, $today)) {
            return [
                'status' => self::ON_VESSEL,
                'label' => 'On '.($vesselName ?: 'vessel'),
                'current_vessel' => $vesselName,
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

        if (
            $deployment->arrived_date !== null
            && $deployment->joined_date === null
            && (
                $deployment->arrived_date->gte($today)
                || (
                    $deployment->join_standby_from !== null
                    && $deployment->join_standby_from->gt($today)
                )
            )
        ) {
            return [
                'status' => self::ARRIVED,
                'label' => 'Arrived',
                'current_vessel' => $vesselName,
            ];
        }

        if (self::isOverdueAfterDisembark($deployment, $today)) {
            return [
                'status' => self::UNKNOWN,
                'label' => 'Needs update',
                'current_vessel' => $vesselName,
            ];
        }

        if (
            $deployment->disembarked_date !== null
            && $deployment->disembarked_date->isSameDay($today)
            && $deployment->travelled_date === null
        ) {
            return [
                'status' => self::DISEMBARKED,
                'label' => 'Disembarked',
                'current_vessel' => null,
            ];
        }

        return [
            'status' => self::UNKNOWN,
            'label' => 'Needs update',
            'current_vessel' => $vesselName,
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

    public static function isInHome(EmployeeDeployment $deployment, ?CarbonImmutable $today = null): bool
    {
        $today ??= CarbonImmutable::today();

        if ($deployment->travelled_date === null || $deployment->travelled_date->gt($today)) {
            return false;
        }

        return self::resolve($deployment, $today)['status'] === self::TRAVEL;
    }

    public static function inHomeDays(EmployeeDeployment $deployment, ?CarbonImmutable $today = null): ?int
    {
        if (! self::isInHome($deployment, $today)) {
            return null;
        }

        $today ??= CarbonImmutable::today();

        return (int) $deployment->travelled_date->diffInDays($today) + 1;
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

        if ($deployment->join_standby_from === null) {
            return false;
        }

        if ($deployment->joined_date !== null && $deployment->joined_date->lte($today)) {
            return false;
        }

        if ($deployment->join_standby_from->gt($today)) {
            return false;
        }

        if ($deployment->join_standby_to === null) {
            return true;
        }

        return $today->lte($deployment->join_standby_to);
    }

    private static function isLeaveStandby(EmployeeDeployment $deployment, CarbonImmutable $today): bool
    {
        if ($deployment->disembarked_date === null || $deployment->disembarked_date->gt($today)) {
            return false;
        }

        if ($deployment->leave_standby_from === null) {
            return false;
        }

        if ($deployment->travelled_date !== null && $deployment->travelled_date->lte($today)) {
            return false;
        }

        if ($deployment->leave_standby_from->gt($today)) {
            return false;
        }

        if ($deployment->leave_standby_to === null) {
            return true;
        }

        return $today->lte($deployment->leave_standby_to);
    }

    /**
     * @return array<string, 'overdue'|'warning'>
     */
    public static function dateFieldHighlights(EmployeeDeployment $deployment, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $highlights = [];
        $status = self::resolve($deployment, $today)['status'];

        if (
            $deployment->join_standby_to !== null
            && self::isJoinStandby($deployment, $today)
            && ($deployment->joined_date === null || $deployment->joined_date->gt($today))
        ) {
            self::applyDateHighlight(
                $highlights,
                'join_standby_to',
                self::highlightLevelForDueDate($deployment->join_standby_to, $today),
            );
        }

        if (
            self::isOnVessel($deployment, $today)
            && $deployment->disembarked_date !== null
            && $deployment->disembarked_date->gte($today)
        ) {
            self::applyDateHighlight(
                $highlights,
                'disembarked_date',
                self::highlightLevelForDueDate($deployment->disembarked_date, $today),
            );
        }

        if (
            $deployment->leave_standby_to !== null
            && self::isLeaveStandby($deployment, $today)
            && $deployment->travelled_date === null
        ) {
            self::applyDateHighlight(
                $highlights,
                'leave_standby_to',
                self::highlightLevelForDueDate($deployment->leave_standby_to, $today),
            );
        }

        if ($status === self::DISEMBARKED) {
            self::applyDateHighlight(
                $highlights,
                'disembarked_date',
                self::highlightLevelForDueDate($deployment->disembarked_date, $today),
            );
        }

        if ($status !== self::UNKNOWN) {
            return $highlights;
        }

        if (
            $deployment->leave_standby_to !== null
            && $deployment->leave_standby_to->lt($today)
            && $deployment->travelled_date === null
            && $deployment->disembarked_date !== null
            && $deployment->disembarked_date->lte($today)
        ) {
            self::applyDateHighlight(
                $highlights,
                'leave_standby_to',
                self::highlightLevelForDueDate($deployment->leave_standby_to, $today),
            );

            return $highlights;
        }

        if (self::isOverdueAfterDisembark($deployment, $today)) {
            self::applyDateHighlight(
                $highlights,
                'disembarked_date',
                self::highlightLevelForDueDate($deployment->disembarked_date, $today),
            );

            return $highlights;
        }

        if (
            $deployment->join_standby_to !== null
            && $deployment->join_standby_to->lt($today)
            && ($deployment->joined_date === null || $deployment->joined_date->gt($today))
        ) {
            self::applyDateHighlight(
                $highlights,
                'join_standby_to',
                self::highlightLevelForDueDate($deployment->join_standby_to, $today),
            );

            return $highlights;
        }

        if (
            $deployment->arrived_date !== null
            && $deployment->arrived_date->lte($today)
            && $deployment->joined_date === null
        ) {
            self::applyDateHighlight(
                $highlights,
                'arrived_date',
                self::highlightLevelForDueDate($deployment->arrived_date, $today),
            );
        }

        return $highlights;
    }

    /**
     * @return list<string>
     */
    public static function overdueDateFields(EmployeeDeployment $deployment, ?CarbonImmutable $today = null): array
    {
        return array_keys(array_filter(
            self::dateFieldHighlights($deployment, $today),
            fn (string $level): bool => $level === 'overdue',
        ));
    }

    /**
     * @return list<string>
     */
    public static function dueSoonDateFields(EmployeeDeployment $deployment, ?CarbonImmutable $today = null): array
    {
        return array_keys(array_filter(
            self::dateFieldHighlights($deployment, $today),
            fn (string $level): bool => $level === 'warning',
        ));
    }

    private static function highlightLevelForDueDate(
        ?CarbonImmutable $dueDate,
        CarbonImmutable $today,
    ): ?string {
        if ($dueDate === null) {
            return null;
        }

        if ($dueDate->lt($today)) {
            return 'overdue';
        }

        if ($dueDate->gt($today) && $dueDate->lte($today->addDays(self::DUE_SOON_WINDOW_DAYS))) {
            return 'warning';
        }

        return null;
    }

    /**
     * @param  array<string, 'overdue'|'warning'>  $highlights
     */
    private static function applyDateHighlight(
        array &$highlights,
        string $field,
        ?string $level,
    ): void {
        if ($level === null) {
            return;
        }

        if (
            ! array_key_exists($field, $highlights)
            || ($highlights[$field] === 'warning' && $level === 'overdue')
        ) {
            $highlights[$field] = $level;
        }
    }

    public static function needsUpdateHint(EmployeeDeployment $deployment, ?CarbonImmutable $today = null): ?string
    {
        $today ??= CarbonImmutable::today();

        if (self::resolve($deployment, $today)['status'] !== self::UNKNOWN) {
            return null;
        }

        if (
            $deployment->leave_standby_to !== null
            && $deployment->leave_standby_to->lt($today)
            && $deployment->travelled_date === null
            && $deployment->disembarked_date !== null
            && $deployment->disembarked_date->lte($today)
        ) {
            $days = (int) $deployment->leave_standby_to->diffInDays($today);

            return sprintf('Leave standby ended %dd ago — add travel date', $days);
        }

        if (self::isOverdueAfterDisembark($deployment, $today)) {
            $days = (int) $deployment->disembarked_date->diffInDays($today);

            return sprintf('Disembarked %dd ago — add travel or standby', $days);
        }

        if (
            $deployment->join_standby_to !== null
            && $deployment->join_standby_to->lt($today)
            && ($deployment->joined_date === null || $deployment->joined_date->gt($today))
        ) {
            $days = (int) $deployment->join_standby_to->diffInDays($today);

            return sprintf('Join standby ended %dd ago — add join date', $days);
        }

        if (
            $deployment->arrived_date !== null
            && $deployment->arrived_date->lt($today)
            && $deployment->joined_date === null
        ) {
            $days = (int) $deployment->arrived_date->diffInDays($today);

            return sprintf('Arrived %dd ago — add join date', $days);
        }

        return 'Dates incomplete — review record';
    }

    private static function isOverdueAfterDisembark(
        EmployeeDeployment $deployment,
        CarbonImmutable $today,
    ): bool {
        if ($deployment->travelled_date !== null) {
            return false;
        }

        if ($deployment->disembarked_date === null || ! $deployment->disembarked_date->lt($today)) {
            return false;
        }

        return ! self::isLeaveStandby($deployment, $today);
    }
}
