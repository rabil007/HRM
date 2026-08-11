<?php

namespace App\Support\CrewOperations;

use App\Enums\CrewOperationalAlertType;
use App\Models\CrewOperationalAlert;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

/**
 * Resolves a permission-safe in-app URL for a Crew operational alert.
 */
final class ResolveCrewOperationalAlertUrl
{
    public function forUser(User $user, CrewOperationalAlert $alert): ?string
    {
        $companyId = (int) $alert->company_id;
        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId($companyId);

            return match ($alert->type) {
                CrewOperationalAlertType::SignoffOverdue,
                CrewOperationalAlertType::SignoffNoRelief,
                CrewOperationalAlertType::ReliefNotReady => $this->assignmentOrCurrentCrew(
                    $user,
                    $alert,
                ),
                CrewOperationalAlertType::CurrentManningGap => $this->vesselManning($user, $alert),
                CrewOperationalAlertType::ProjectedManningGap => $this->projectedManningGap($user, $alert),
            };
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }
    }

    private function projectedManningGap(User $user, CrewOperationalAlert $alert): ?string
    {
        if ($user->can('crew_operations.planning.view')) {
            $params = [];
            $vesselId = $alert->context['vessel_id'] ?? null;
            $rankId = $alert->context['rank_id'] ?? null;

            if (is_numeric($vesselId)) {
                $params['vessel_id'] = (int) $vesselId;
            }

            if (is_numeric($rankId)) {
                $params['rank_id'] = (int) $rankId;
            }

            return route('organization.crew-planning.index', $params);
        }

        if ($user->can('crew_operations.overview.view')) {
            return route('organization.crew-operations.index');
        }

        if ($this->canViewVesselsModule($user)) {
            $vesselId = $alert->context['vessel_id'] ?? null;

            if (is_numeric($vesselId)) {
                return route('organization.vessels.show', ['vessel' => (int) $vesselId]);
            }

            return route('organization.vessels.index');
        }

        return null;
    }

    private function assignmentOrCurrentCrew(User $user, CrewOperationalAlert $alert): ?string
    {
        $assignmentId = $alert->context['assignment_id'] ?? null;

        if (is_numeric($assignmentId) && $user->can('crew_operations.assignments.view')) {
            return route('organization.crew-assignments.show', ['assignment' => (int) $assignmentId]);
        }

        if ($user->can('crew_operations.assignments.view')) {
            return route('organization.crew-assignments.index');
        }

        return $this->fallbackOverview($user);
    }

    private function vesselManning(User $user, CrewOperationalAlert $alert): ?string
    {
        if (! $this->canViewVesselsModule($user)) {
            return $this->fallbackOverview($user);
        }

        $vesselId = $alert->context['vessel_id'] ?? null;

        if (is_numeric($vesselId)) {
            return route('organization.vessels.show', ['vessel' => (int) $vesselId]);
        }

        return route('organization.vessels.index');
    }

    private function canViewVesselsModule(User $user): bool
    {
        return $user->can('crew_operations.vessels.view')
            || $user->can('crew_operations.vessel_manning.view');
    }

    private function fallbackOverview(User $user): ?string
    {
        if ($user->can('crew_operations.overview.view')) {
            return route('organization.crew-operations.index');
        }

        return null;
    }
}
