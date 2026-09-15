<?php

namespace App\Support\CrewMovements\Actions;

use App\Exceptions\CrewMovementException;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\Settings\CompanyTimezone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BulkStartCrewAssignments
{
    public function __construct(
        private CrewMovementService $service,
    ) {}

    /**
     * Start one normal Crew Assignment per crew row, all-or-nothing.
     *
     * Rows are processed in employee-id order to keep lock acquisition
     * deterministic. Validation errors keep the original request row index.
     *
     * @param  array{
     *     client_id?: int|null,
     *     vessel_id?: int|null,
     *     planned_join_at?: string|null,
     *     current_stage?: string|null,
     *     remarks?: string|null,
     *     crew: list<array{employee_id: int, rank_id?: int|null}>
     * }  $payload
     * @return list<CrewAssignment>
     */
    public function handle(int $companyId, array $payload, ?int $actorId = null): array
    {
        $crew = array_values($payload['crew'] ?? []);

        if ($crew === []) {
            throw ValidationException::withMessages([
                'crew' => 'Add at least one crew member.',
            ]);
        }

        return DB::transaction(function () use ($companyId, $payload, $crew, $actorId): array {
            $startedAt = now(CompanyTimezone::forCompanyId($companyId));
            $namesById = $this->employeeNames($companyId, $crew);
            $created = [];

            foreach ($this->rowsInLockOrder($crew) as $row) {
                try {
                    $created[] = $this->service->startAssignment(
                        $companyId,
                        $row['employee_id'],
                        [
                            'rank_id' => $row['rank_id'],
                            'client_id' => $payload['client_id'] ?? null,
                            'vessel_id' => $payload['vessel_id'] ?? null,
                            'planned_join_at' => $payload['planned_join_at'] ?? null,
                            'current_stage' => $payload['current_stage'] ?? null,
                            'remarks' => $payload['remarks'] ?? null,
                            'stage_started_at' => $startedAt->format('Y-m-d H:i:s'),
                        ],
                        $actorId,
                    );
                } catch (CrewMovementException $exception) {
                    throw ValidationException::withMessages([
                        "crew.{$row['index']}.employee_id" => $this->messageForRow(
                            $namesById[$row['employee_id']] ?? null,
                            $exception,
                        ),
                    ]);
                }
            }

            return $created;
        });
    }

    /**
     * @param  list<array{employee_id: int, rank_id?: int|null}>  $crew
     * @return list<array{index: int, employee_id: int, rank_id: int|null}>
     */
    private function rowsInLockOrder(array $crew): array
    {
        $indexed = [];

        foreach ($crew as $index => $row) {
            $indexed[] = [
                'index' => (int) $index,
                'employee_id' => (int) $row['employee_id'],
                'rank_id' => isset($row['rank_id']) && $row['rank_id'] !== null
                    ? (int) $row['rank_id']
                    : null,
            ];
        }

        usort(
            $indexed,
            static fn (array $left, array $right): int => $left['employee_id'] <=> $right['employee_id']
                ?: $left['index'] <=> $right['index'],
        );

        return $indexed;
    }

    /**
     * @param  list<array{employee_id: int, rank_id?: int|null}>  $crew
     * @return array<int, string>
     */
    private function employeeNames(int $companyId, array $crew): array
    {
        $employeeIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['employee_id'],
            $crew,
        )));

        return Employee::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $employeeIds)
            ->pluck('name', 'id')
            ->mapWithKeys(fn ($name, $id): array => [(int) $id => (string) $name])
            ->all();
    }

    private function messageForRow(?string $employeeName, CrewMovementException $exception): string
    {
        $name = is_string($employeeName) && trim($employeeName) !== ''
            ? trim($employeeName)
            : 'This employee';

        if ($exception->errorCode === 'active_assignment_exists') {
            return "{$name} already has an active Crew Assignment.";
        }

        return "{$name}: {$exception->getMessage()}";
    }
}
