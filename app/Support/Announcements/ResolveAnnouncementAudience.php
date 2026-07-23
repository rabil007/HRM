<?php

namespace App\Support\Announcements;

use App\Enums\AnnouncementAudienceType;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class ResolveAnnouncementAudience
{
    /**
     * @param  list<array{type: string, id?: int|null}>  $audiences
     * @return Collection<int, Employee>
     */
    public function handle(int $companyId, array $audiences): Collection
    {
        $this->assertAudiencesBelongToCompany($companyId, $audiences);

        $query = Employee::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->with(['user:id,email']);

        $hasAll = collect($audiences)->contains(
            fn (array $audience): bool => ($audience['type'] ?? '') === AnnouncementAudienceType::AllEmployees->value
        );

        if ($hasAll) {
            return $query->orderBy('name')->get();
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

        $query->where(function ($builder) use ($departmentIds, $branchIds, $positionIds, $employeeIds): void {
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

        return $query->orderBy('name')->get();
    }

    /**
     * @param  list<array{type: string, id?: int|null}>  $audiences
     */
    public function assertAudiencesBelongToCompany(int $companyId, array $audiences): void
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
                AnnouncementAudienceType::Employee => Employee::query()
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
     * @return list<int>
     */
    private function idsForType(array $audiences, AnnouncementAudienceType $type): array
    {
        return collect($audiences)
            ->filter(fn (array $audience): bool => ($audience['type'] ?? '') === $type->value)
            ->map(fn (array $audience): int => (int) ($audience['id'] ?? 0))
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }
}
