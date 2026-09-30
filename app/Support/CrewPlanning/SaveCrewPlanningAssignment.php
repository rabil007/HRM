<?php

namespace App\Support\CrewPlanning;

use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\User;
use App\Support\CrewMovements\CrewReliefReadinessResolver;
use App\Support\Positions\RankPositionBridge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Authoritative create/update for Crew Planning rows.
 *
 * Form Requests provide early UX validation; this action re-locks the source
 * assignment and rechecks operational relief exclusivity inside the write transaction.
 */
final class SaveCrewPlanningAssignment
{
    public function __construct(
        private readonly CrewReliefReadinessResolver $reliefResolver = new CrewReliefReadinessResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(int $companyId, array $attributes, ?User $actor = null): CrewPlanningAssignment
    {
        return DB::transaction(function () use ($companyId, $attributes, $actor): CrewPlanningAssignment {
            $attributes = self::normalizePositionAndRank($companyId, $attributes);
            $this->assertReliefConstraints($companyId, $attributes, null, $actor);

            return CrewPlanningAssignment::query()->create([
                ...$attributes,
                'employee_id' => null,
                'company_id' => $companyId,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(
        CrewPlanningAssignment $assignment,
        int $companyId,
        array $attributes,
        ?User $actor = null,
    ): CrewPlanningAssignment {
        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return DB::transaction(function () use ($assignment, $companyId, $attributes, $actor): CrewPlanningAssignment {
                    $hasIncomingRelief = array_key_exists('relieves_crew_assignment_id', $attributes);
                    $incomingRelievesId = $hasIncomingRelief && $attributes['relieves_crew_assignment_id'] !== null && $attributes['relieves_crew_assignment_id'] !== ''
                        ? (int) $attributes['relieves_crew_assignment_id']
                        : null;

                    $preReadRelievesId = CrewPlanningAssignment::query()
                        ->whereKey($assignment->id)
                        ->value('relieves_crew_assignment_id');
                    $preReadRelievesId = $preReadRelievesId !== null && $preReadRelievesId !== ''
                        ? (int) $preReadRelievesId
                        : null;

                    $idsToLock = array_values(array_filter(array_unique([
                        $preReadRelievesId,
                        $incomingRelievesId,
                    ])));
                    sort($idsToLock);

                    foreach ($idsToLock as $assignmentId) {
                        CrewAssignment::query()
                            ->where('company_id', $companyId)
                            ->whereKey($assignmentId)
                            ->lockForUpdate()
                            ->first();
                    }

                    $locked = CrewPlanningAssignment::query()
                        ->where('company_id', $companyId)
                        ->whereKey($assignment->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $lockedRelievesId = $locked->relieves_crew_assignment_id !== null && $locked->relieves_crew_assignment_id !== ''
                        ? (int) $locked->relieves_crew_assignment_id
                        : null;

                    if ($lockedRelievesId !== $preReadRelievesId) {
                        throw new PlanningConcurrencyConflictException('The planning assignment was modified concurrently.');
                    }

                    if ($locked->crew_assignment_id !== null) {
                        throw ValidationException::withMessages([
                            'error' => 'This planning bar is controlled by Crew Assignments. Update the linked crew assignment instead.',
                        ]);
                    }

                    $attributes = self::normalizePositionAndRank($companyId, $attributes);

                    $merged = [
                        'relieves_crew_assignment_id' => array_key_exists('relieves_crew_assignment_id', $attributes)
                            ? $attributes['relieves_crew_assignment_id']
                            : $locked->relieves_crew_assignment_id,
                        'vessel_id' => array_key_exists('vessel_id', $attributes)
                            ? $attributes['vessel_id']
                            : $locked->vessel_id,
                        'position_id' => $attributes['position_id'] ?? $locked->position_id,
                        'rank_id' => $attributes['rank_id'] ?? $locked->rank_id,
                        'employee_id' => null,
                    ];

                    $this->assertReliefConstraints($companyId, $merged, (int) $locked->id, $actor);

                    $locked->update($attributes);

                    return $locked->fresh() ?? $locked;
                });
            } catch (PlanningConcurrencyConflictException $exception) {
                if ($attempt < $maxAttempts) {
                    usleep(10000);

                    continue;
                }

                throw ValidationException::withMessages([
                    'error' => 'The planning assignment was modified concurrently. Please refresh and try again.',
                ]);
            }
        }

        throw ValidationException::withMessages([
            'error' => 'The planning assignment was modified concurrently. Please refresh and try again.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertReliefConstraints(
        int $companyId,
        array $attributes,
        ?int $exceptPlanningId = null,
        ?User $actor = null,
    ): void {
        $relievesId = $attributes['relieves_crew_assignment_id'] ?? null;

        if ($relievesId === null || $relievesId === '') {
            return;
        }

        CrewAssignment::query()
            ->where('company_id', $companyId)
            ->whereKey((int) $relievesId)
            ->lockForUpdate()
            ->first();

        $existing = $exceptPlanningId !== null
            ? CrewPlanningAssignment::query()->whereKey($exceptPlanningId)->first()
            : null;

        $validator = Validator::make([], []);

        ValidatesCrewPlanningReliefLink::validate($validator, [
            'company_id' => $companyId,
            'relieves_crew_assignment_id' => $relievesId,
            'vessel_id' => $attributes['vessel_id'] ?? null,
            'position_id' => $attributes['position_id'] ?? null,
            'employee_id' => null,
        ], $existing, $actor);

        if ($validator->errors()->isNotEmpty()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function normalizePositionAndRank(int $companyId, array $attributes): array
    {
        return RankPositionBridge::syncEmployeePositionAndRank($attributes, $companyId);
    }
}
