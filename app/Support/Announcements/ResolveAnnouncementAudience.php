<?php

namespace App\Support\Announcements;

use App\Enums\AnnouncementAudienceType;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class ResolveAnnouncementAudience
{
    /**
     * @param  list<array{type: string, id?: int|null}>  $audiences
     * @return Collection<int, Employee>
     */
    public function handle(int $companyId, array $audiences, ?User $publisher = null): Collection
    {
        $this->assertAudiencesBelongToCompany($companyId, $audiences, $publisher);

        $audiences = $this->normalizeAudiences($companyId, $audiences, $publisher);

        $query = Employee::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->with(['user:id,email']);

        EmployeeVisibilityScope::apply($query, $publisher, $companyId);

        return $this->applyBusinessAudiences($query, $audiences)->orderBy('name')->get();
    }

    /**
     * Resolve scheduled announcement recipients within a frozen authorization snapshot.
     *
     * Current business audience eligibility is intersected with the stored snapshot.
     *
     * @param  list<array{type: string, id?: int|null}>  $audiences
     * @param  list<int>  $authorizedEmployeeIds
     * @return Collection<int, Employee>
     */
    public function handleWithinAuthorizedEmployees(
        int $companyId,
        array $audiences,
        array $authorizedEmployeeIds,
    ): Collection {
        $authorizedEmployeeIds = array_values(array_unique(array_filter(
            array_map(intval(...), $authorizedEmployeeIds),
            fn (int $id): bool => $id > 0,
        )));

        if ($authorizedEmployeeIds === []) {
            return Collection::make();
        }

        $this->assertAudiencesBelongToCompany($companyId, $audiences);
        $audiences = $this->normalizeAudiences($companyId, $audiences);

        $query = Employee::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->whereIn('id', $authorizedEmployeeIds)
            ->with(['user:id,email']);

        return $this->applyBusinessAudiences($query, $audiences)->orderBy('name')->get();
    }

    /**
     * @param  Builder<Employee>  $query
     * @param  list<array{type: string, id?: int|null}>  $audiences
     * @return Builder<Employee>
     */
    private function applyBusinessAudiences(Builder $query, array $audiences): Builder
    {
        $hasAll = collect($audiences)->contains(
            fn (array $audience): bool => ($audience['type'] ?? '') === AnnouncementAudienceType::AllEmployees->value
        );

        if ($hasAll) {
            return $query;
        }

        $departmentIds = $this->idsForType($audiences, AnnouncementAudienceType::Department);
        $branchIds = $this->idsForType($audiences, AnnouncementAudienceType::Branch);
        $positionIds = $this->idsForType($audiences, AnnouncementAudienceType::Position);
        $employeeIds = $this->idsForType($audiences, AnnouncementAudienceType::Employee);

        if ($departmentIds === [] && $branchIds === [] && $positionIds === [] && $employeeIds === []) {
            throw ValidationException::withMessages([
                'audiences' => 'Select at least one audience.',
            ]);
        }

        return $query->where(function ($builder) use ($departmentIds, $branchIds, $positionIds, $employeeIds): void {
            if ($departmentIds !== []) {
                $builder->orWhereIn('department_id', $departmentIds);
            }

            if ($branchIds !== []) {
                $builder->orWhereIn('branch_id', $branchIds);
            }

            if ($positionIds !== []) {
                $builder->orWhereIn('position_id', $positionIds);
            }

            if ($employeeIds !== []) {
                $builder->orWhereIn('id', $employeeIds);
            }
        });
    }

    /**
     * @param  list<array{type: string, id?: int|null}>  $audiences
     */
    public function assertAudiencesBelongToCompany(int $companyId, array $audiences, ?User $publisher = null): void
    {
        foreach ($audiences as $audience) {
            $type = AnnouncementAudienceType::tryFrom((string) ($audience['type'] ?? ''));
            $id = isset($audience['id']) ? (int) $audience['id'] : null;

            if ($type === null) {
                throw ValidationException::withMessages([
                    'audiences' => 'Invalid audience type.',
                ]);
            }

            if ($type === AnnouncementAudienceType::AllEmployees) {
                continue;
            }

            if ($id === null || $id < 1) {
                throw ValidationException::withMessages([
                    'audiences' => 'Audience selection is incomplete.',
                ]);
            }

            if ($type === AnnouncementAudienceType::Employee) {
                $employee = Employee::query()
                    ->where('company_id', $companyId)
                    ->whereKey($id)
                    ->active()
                    ->first();

                if (
                    $employee === null
                    || ($publisher !== null && ! EmployeeVisibilityScope::canAccess($publisher, $employee, $companyId))
                ) {
                    throw ValidationException::withMessages([
                        'audiences' => 'One or more audience selections are invalid for this company.',
                    ]);
                }

                continue;
            }

            $exists = match ($type) {
                AnnouncementAudienceType::Department => Department::query()
                    ->where('company_id', $companyId)
                    ->whereKey($id)
                    ->exists(),
                AnnouncementAudienceType::Branch => Branch::query()
                    ->where('company_id', $companyId)
                    ->whereKey($id)
                    ->exists(),
                AnnouncementAudienceType::Position => Position::query()
                    ->where('company_id', $companyId)
                    ->whereKey($id)
                    ->exists(),
                default => false,
            };

            if (! $exists) {
                throw ValidationException::withMessages([
                    'audiences' => 'One or more audience selections are invalid for this company.',
                ]);
            }
        }
    }

    /**
     * @param  list<array{type: string, id?: int|null}>  $audiences
     * @return list<array{type: string, id?: int|null}>
     */
    public function normalizeAudiences(int $companyId, array $audiences, ?User $publisher = null): array
    {
        if (collect($audiences)->contains(
            fn (array $audience): bool => ($audience['type'] ?? '') === AnnouncementAudienceType::AllEmployees->value
        )) {
            return [[
                'type' => AnnouncementAudienceType::AllEmployees->value,
                'id' => null,
            ]];
        }

        $employeeIds = $this->idsForType($audiences, AnnouncementAudienceType::Employee);
        $hasOtherAudienceTypes = collect($audiences)->contains(
            fn (array $audience): bool => ($audience['type'] ?? '') !== AnnouncementAudienceType::Employee->value
        );

        if ($hasOtherAudienceTypes || $employeeIds === []) {
            return $audiences;
        }

        $activeEmployeeQuery = Employee::query()
            ->where('status', 'active')
            ->orderBy('id');

        EmployeeVisibilityScope::apply($activeEmployeeQuery, $publisher, $companyId);

        $activeEmployeeIds = $activeEmployeeQuery
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($activeEmployeeIds !== [] && $employeeIds === $activeEmployeeIds) {
            return [[
                'type' => AnnouncementAudienceType::AllEmployees->value,
                'id' => null,
            ]];
        }

        return $audiences;
    }

    /**
     * @param  list<array{type: string, id?: int|null}>  $audiences
     * @return list<int>
     */
    public function authorizedEmployeeIds(int $companyId, array $audiences, User $publisher): array
    {
        return $this->handle($companyId, $audiences, $publisher)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  list<array{type: string, id?: int|null}>  $audiences
     * @return list<int>
     */
    private function idsForType(array $audiences, AnnouncementAudienceType $type): array
    {
        return collect($audiences)
            ->filter(fn (array $audience): bool => ($audience['type'] ?? '') === $type->value)
            ->map(fn (array $audience): int => (int) ($audience['id'] ?? 0))
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
