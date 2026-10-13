<?php

namespace App\Support\CrewMovements\Actions;

use App\Enums\CrewPhaseCode;
use App\Exceptions\CrewMovementException;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Support\CrewMovements\CrewAlreadyArrivedStart;
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
     *     remarks?: string|null,
     *     already_arrived?: bool|string|int|null,
     *     crew: list<array{
     *         employee_id: int,
     *         position_id?: int|null,
     *         planned_arrival_at?: string|null,
     *         accommodation_status?: string|null,
     *         hotel_id?: int|null,
     *         room_type_id?: int|null,
     *         check_in_date?: string|null
     *     }>
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
            $alreadyArrived = CrewAlreadyArrivedStart::isEnabled($payload['already_arrived'] ?? false);
            $startedAt = now(CompanyTimezone::forCompanyId($companyId));
            $namesById = $this->employeeNames($companyId, $crew);
            $created = [];

            foreach ($this->rowsInLockOrder($crew) as $row) {
                try {
                    $attributes = [
                        'position_id' => $row['position_id'] ?? null,
                        'client_id' => $payload['client_id'] ?? null,
                        'vessel_id' => $payload['vessel_id'] ?? null,
                        'planned_join_at' => $payload['planned_join_at'] ?? null,
                        'current_stage' => CrewPhaseCode::PreMobilisation->value,
                        'already_arrived' => $alreadyArrived,
                        'remarks' => $payload['remarks'] ?? null,
                    ];

                    if ($alreadyArrived) {
                        // Per-row form date is Actual Arrival Date only — do not fabricate planned arrival.
                        $attributes['actual_arrival_at'] = $row['planned_arrival_at'] ?? null;
                        $attributes['planned_arrival_at'] = null;
                        $attributes['accommodation_status'] = $row['accommodation_status'] ?? null;
                        $attributes['hotel_id'] = $row['hotel_id'] ?? null;
                        $attributes['room_type_id'] = $row['room_type_id'] ?? null;
                        $attributes['check_in_date'] = $row['check_in_date'] ?? null;
                    } else {
                        $attributes['planned_arrival_at'] = $row['planned_arrival_at'] ?? null;
                        $attributes['stage_started_at'] = $startedAt->format('Y-m-d H:i:s');
                    }

                    $created[] = $this->service->startAssignment(
                        $companyId,
                        $row['employee_id'],
                        $attributes,
                        $actorId,
                    );
                } catch (CrewMovementException $exception) {
                    $field = match ($exception->errorCode) {
                        'already_arrived_arrival_required', 'occurred_at_in_future' => "crew.{$row['index']}.planned_arrival_at",
                        'hotel_required', 'invalid_hotel' => "crew.{$row['index']}.hotel_id",
                        'check_in_required', 'check_in_before_arrival' => "crew.{$row['index']}.check_in_date",
                        'invalid_room_type' => "crew.{$row['index']}.room_type_id",
                        default => "crew.{$row['index']}.employee_id",
                    };

                    throw ValidationException::withMessages([
                        $field => $this->messageForRow(
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
     * @param  list<array{employee_id: int, position_id?: int|null, planned_arrival_at?: string|null}>  $crew
     * @return list<array{
     *     index: int,
     *     employee_id: int,
     *     position_id: int|null,
     *     planned_arrival_at: string|null,
     *     accommodation_status: string|null,
     *     hotel_id: int|null,
     *     room_type_id: int|null,
     *     check_in_date: string|null
     * }>
     */
    private function rowsInLockOrder(array $crew): array
    {
        $indexed = [];

        foreach ($crew as $index => $row) {
            $indexed[] = [
                'index' => (int) $index,
                'employee_id' => (int) $row['employee_id'],
                'position_id' => isset($row['position_id']) && $row['position_id'] !== null
                    ? (int) $row['position_id']
                    : null,
                'planned_arrival_at' => isset($row['planned_arrival_at']) && $row['planned_arrival_at'] !== ''
                    ? (string) $row['planned_arrival_at']
                    : null,
                'accommodation_status' => isset($row['accommodation_status']) && $row['accommodation_status'] !== ''
                    ? (string) $row['accommodation_status']
                    : null,
                'hotel_id' => isset($row['hotel_id']) && $row['hotel_id'] !== null && $row['hotel_id'] !== ''
                    ? (int) $row['hotel_id']
                    : null,
                'room_type_id' => isset($row['room_type_id']) && $row['room_type_id'] !== null && $row['room_type_id'] !== ''
                    ? (int) $row['room_type_id']
                    : null,
                'check_in_date' => isset($row['check_in_date']) && $row['check_in_date'] !== ''
                    ? (string) $row['check_in_date']
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
     * @param  list<array{employee_id: int, position_id?: int|null}>  $crew
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
