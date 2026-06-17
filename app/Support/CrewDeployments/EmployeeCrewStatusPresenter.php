<?php

namespace App\Support\CrewDeployments;

use App\Models\EmployeeDeployment;
use Carbon\CarbonImmutable;

final class EmployeeCrewStatusPresenter
{
    /**
     * @return array{
     *     deployment_id: int|null,
     *     status: string,
     *     label: string,
     *     hint: null,
     *     current_vessel: string|null,
     *     in_home_days: int|null,
     *     vessel_name: string|null,
     * }
     */
    public static function fromDeployment(?EmployeeDeployment $deployment, ?CarbonImmutable $today = null): array
    {
        if ($deployment === null) {
            return self::available();
        }

        $today ??= CarbonImmutable::today();

        $deployment->loadMissing(['vessel:id,name']);

        $vesselName = $deployment->vessel?->name;

        if (DeploymentStatus::isInHome($deployment, $today)) {
            $inHomeDays = DeploymentStatus::inHomeDays($deployment, $today);

            return self::payload(
                deployment: $deployment,
                status: DeploymentStatus::IN_HOME,
                label: $inHomeDays !== null ? "In home · {$inHomeDays}d" : 'In home',
                currentVessel: null,
                inHomeDays: $inHomeDays,
                vesselName: $vesselName,
            );
        }

        $resolved = DeploymentStatus::resolve($deployment, $today);

        if ($resolved['status'] === DeploymentStatus::UNKNOWN) {
            $resolved = self::bestGuessForProfile($deployment, $today, $vesselName);
        }

        return self::payload(
            deployment: $deployment,
            status: $resolved['status'],
            label: self::profileLabel($resolved['status'], $resolved['label']),
            currentVessel: $resolved['current_vessel'],
            inHomeDays: null,
            vesselName: $vesselName,
        );
    }

    private static function profileLabel(string $status, string $resolvedLabel): string
    {
        return match ($status) {
            DeploymentStatus::ON_VESSEL => 'On vessel',
            default => $resolvedLabel,
        };
    }

    /**
     * @return array{status: string, label: string, current_vessel: string|null}
     */
    private static function bestGuessForProfile(
        EmployeeDeployment $deployment,
        CarbonImmutable $today,
        ?string $vesselName,
    ): array {
        if (
            $deployment->joined_date !== null
            && $deployment->joined_date->lte($today)
            && ($deployment->disembarked_date === null || $deployment->disembarked_date->gt($today))
        ) {
            return [
                'status' => DeploymentStatus::ON_VESSEL,
                'label' => 'On vessel',
                'current_vessel' => $vesselName,
            ];
        }

        if (
            $deployment->disembarked_date !== null
            && $deployment->disembarked_date->lt($today)
            && $deployment->travelled_date === null
        ) {
            return [
                'status' => DeploymentStatus::DISEMBARKED,
                'label' => 'Disembarked',
                'current_vessel' => null,
            ];
        }

        if ($deployment->leave_standby_from !== null && $deployment->travelled_date === null) {
            return [
                'status' => DeploymentStatus::LEAVE_STANDBY,
                'label' => 'Leave standby',
                'current_vessel' => null,
            ];
        }

        if (
            $deployment->join_standby_from !== null
            && ($deployment->joined_date === null || $deployment->joined_date->gt($today))
        ) {
            return [
                'status' => DeploymentStatus::JOIN_STANDBY,
                'label' => 'Join standby',
                'current_vessel' => null,
            ];
        }

        if ($deployment->arrived_date !== null && $deployment->joined_date === null) {
            return [
                'status' => DeploymentStatus::ARRIVED,
                'label' => 'Arrived',
                'current_vessel' => $vesselName,
            ];
        }

        return [
            'status' => 'available',
            'label' => 'Available',
            'current_vessel' => null,
        ];
    }

    /**
     * @return array{
     *     deployment_id: int|null,
     *     status: string,
     *     label: string,
     *     hint: null,
     *     current_vessel: string|null,
     *     in_home_days: int|null,
     *     vessel_name: string|null,
     * }
     */
    private static function available(): array
    {
        return [
            'deployment_id' => null,
            'status' => 'available',
            'label' => 'Available',
            'hint' => null,
            'current_vessel' => null,
            'in_home_days' => null,
            'vessel_name' => null,
        ];
    }

    /**
     * @return array{
     *     deployment_id: int|null,
     *     status: string,
     *     label: string,
     *     hint: null,
     *     current_vessel: string|null,
     *     in_home_days: int|null,
     *     vessel_name: string|null,
     * }
     */
    private static function payload(
        EmployeeDeployment $deployment,
        string $status,
        string $label,
        ?string $currentVessel,
        ?int $inHomeDays,
        ?string $vesselName,
    ): array {
        return [
            'deployment_id' => $deployment->id,
            'status' => $status,
            'label' => $label,
            'hint' => null,
            'current_vessel' => $currentVessel,
            'in_home_days' => $inHomeDays,
            'vessel_name' => $vesselName,
        ];
    }
}
