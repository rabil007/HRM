<?php

namespace App\Support\CrewMovements;

use App\Models\CrewAssignment;
use Illuminate\Support\Facades\DB;

/**
 * Collects the full previous/next linked assignment chain for one employee.
 *
 * Used so payable-date ownership matches automatic payroll for vessel transfers
 * and redeployments (A→B→C) without pulling unrelated same-employee assignments.
 */
final class CrewAssignmentLinkChain
{
    /**
     * @return list<int>
     */
    public static function assignmentIds(int $companyId, int $employeeId, int $assignmentId): array
    {
        if ($companyId < 1 || $employeeId < 1 || $assignmentId < 1) {
            return [];
        }

        return self::assignmentIdsFromLinks(
            self::loadLinks($companyId, $employeeId),
            $assignmentId,
        );
    }

    /**
     * @param  iterable<int, CrewAssignment>  $assignments
     * @return array<int, list<int>> assignment id => chain assignment ids
     */
    public static function assignmentIdsByRootAssignments(iterable $assignments): array
    {
        /** @var array<string, list<CrewAssignment>> $byEmployee */
        $byEmployee = [];

        foreach ($assignments as $assignment) {
            if (! $assignment instanceof CrewAssignment) {
                continue;
            }

            $companyId = (int) $assignment->company_id;
            $employeeId = (int) $assignment->employee_id;
            $assignmentId = (int) $assignment->id;

            if ($companyId < 1 || $employeeId < 1 || $assignmentId < 1) {
                continue;
            }

            $byEmployee[$companyId.'|'.$employeeId][] = $assignment;
        }

        $result = [];

        foreach ($byEmployee as $group) {
            $first = $group[0];
            $companyId = (int) $first->company_id;
            $employeeId = (int) $first->employee_id;
            $links = self::loadLinks($companyId, $employeeId);
            $chainCache = [];

            foreach ($group as $assignment) {
                $assignmentId = (int) $assignment->id;
                $result[$assignmentId] = $chainCache[$assignmentId] ??= self::assignmentIdsFromLinks(
                    $links,
                    $assignmentId,
                );
            }
        }

        return $result;
    }

    /**
     * @return list<object{id: int|string, previous_assignment_id: int|string|null}>
     */
    private static function loadLinks(int $companyId, int $employeeId): array
    {
        return DB::table('crew_assignments')
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->get(['id', 'previous_assignment_id'])
            ->all();
    }

    /**
     * @param  list<object{id: int|string, previous_assignment_id: int|string|null}>  $links
     * @return list<int>
     */
    private static function assignmentIdsFromLinks(array $links, int $assignmentId): array
    {
        if ($links === []) {
            return [$assignmentId];
        }

        /** @var array<int, int|null> $previousById */
        $previousById = [];
        /** @var array<int, list<int>> $nextById */
        $nextById = [];

        foreach ($links as $link) {
            $id = (int) $link->id;
            $previousId = $link->previous_assignment_id !== null
                ? (int) $link->previous_assignment_id
                : null;
            $previousById[$id] = $previousId;

            if ($previousId !== null) {
                $nextById[$previousId][] = $id;
            }
        }

        if (! array_key_exists($assignmentId, $previousById)) {
            return [$assignmentId];
        }

        $root = $assignmentId;

        while (($previousById[$root] ?? null) !== null && array_key_exists((int) $previousById[$root], $previousById)) {
            $root = (int) $previousById[$root];
        }

        $ids = [];
        $queue = [$root];

        while ($queue !== []) {
            $current = array_shift($queue);
            $ids[] = $current;

            foreach ($nextById[$current] ?? [] as $nextId) {
                $queue[] = $nextId;
            }
        }

        sort($ids);

        return $ids;
    }
}
