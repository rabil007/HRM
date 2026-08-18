<?php

namespace App\Support\RecentItems;

use App\Enums\RecentItemType;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\PayrollPeriod;
use App\Models\RecentItem;
use App\Models\User;
use App\Models\Vessel;
use App\Support\Search\GlobalSearchResultPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class ListRecentItems
{
    public function __construct(
        private readonly GlobalSearchResultPresenter $presenter = new GlobalSearchResultPresenter,
    ) {}

    /**
     * @return array{items: list<array{id: string, type: string, type_label: string, title: string, subtitle: string, href: string}>}
     */
    public function handle(?User $user, ?int $companyId): array
    {
        if ($user === null || $companyId === null || $companyId < 1) {
            return ['items' => []];
        }

        $rows = RecentItem::query()
            ->where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->orderByDesc('last_viewed_at')
            ->orderByDesc('id')
            ->limit(RecentItem::MAX_PER_USER_COMPANY)
            ->get(['id', 'record_type', 'record_id']);

        if ($rows->isEmpty()) {
            return ['items' => []];
        }

        $idsByType = [];

        foreach ($rows as $row) {
            $type = $row->record_type instanceof RecentItemType
                ? $row->record_type
                : RecentItemType::tryFrom((string) $row->record_type);

            if ($type === null || ! $type->isAccessible($user)) {
                continue;
            }

            $idsByType[$type->value][] = (int) $row->record_id;
        }

        $presentedById = [];

        foreach (RecentItemType::cases() as $type) {
            $ids = array_values(array_unique($idsByType[$type->value] ?? []));

            if ($ids === []) {
                continue;
            }

            foreach ($this->present($type, $this->loadRecords($type, $companyId, $ids)) as $item) {
                $presentedById[$item['id']] = [
                    ...$item,
                    'type' => $type->value,
                    'type_label' => $type->label(),
                ];
            }
        }

        $items = [];
        $staleIds = [];

        foreach ($rows as $row) {
            $type = $row->record_type instanceof RecentItemType
                ? $row->record_type
                : RecentItemType::tryFrom((string) $row->record_type);

            if ($type === null) {
                $staleIds[] = $row->id;

                continue;
            }

            if (! $type->isAccessible($user)) {
                continue;
            }

            $key = $type->resultId((int) $row->record_id);

            if (! isset($presentedById[$key])) {
                $staleIds[] = $row->id;

                continue;
            }

            $items[] = $presentedById[$key];
        }

        if ($staleIds !== []) {
            RecentItem::query()
                ->where('user_id', $user->id)
                ->whereIn('id', $staleIds)
                ->delete();
        }

        return ['items' => $items];
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Employee|EmployeeDocument|CrewAssignment|Vessel|PayrollPeriod>
     */
    private function loadRecords(RecentItemType $type, int $companyId, array $ids): Collection
    {
        return match ($type) {
            RecentItemType::Employee => Employee::query()
                ->where('company_id', $companyId)
                ->whereIn('id', $ids)
                ->with(['department:id,name', 'position:id,title'])
                ->get(['id', 'name', 'employee_no', 'department_id', 'position_id']),
            RecentItemType::Document => EmployeeDocument::query()
                ->where('company_id', $companyId)
                ->whereIn('id', $ids)
                ->with([
                    'employee:id,name,employee_no,company_id',
                    'documentType:id,title',
                ])
                ->whereHas('employee', function (Builder $employee) use ($companyId): void {
                    $employee->where('company_id', $companyId);
                })
                ->get(['id', 'employee_id', 'document_type_id', 'title', 'document_number', 'expiry_date']),
            RecentItemType::CrewAssignment => CrewAssignment::query()
                ->where('company_id', $companyId)
                ->whereIn('id', $ids)
                ->with([
                    'employee:id,name,employee_no,company_id',
                    'vessel:id,name,company_id',
                    'currentPhase:id,phase_code',
                ])
                ->get(['id', 'assignment_no', 'employee_id', 'vessel_id', 'current_phase_id']),
            RecentItemType::Vessel => Vessel::query()
                ->where('company_id', $companyId)
                ->whereIn('id', $ids)
                ->get(['id', 'name', 'imo_no', 'official_no']),
            RecentItemType::PayrollPeriod => PayrollPeriod::query()
                ->where('company_id', $companyId)
                ->whereIn('id', $ids)
                ->get(['id', 'name', 'start_date', 'end_date', 'status']),
        };
    }

    /**
     * @param  Collection<int, Employee|EmployeeDocument|CrewAssignment|Vessel|PayrollPeriod>  $records
     * @return list<array{id: string, title: string, subtitle: string, href: string}>
     */
    private function present(RecentItemType $type, Collection $records): array
    {
        if ($records->isEmpty()) {
            return [];
        }

        return match ($type) {
            RecentItemType::Employee => $this->presenter->employees($records),
            RecentItemType::Document => $this->presenter->documents($records),
            RecentItemType::CrewAssignment => $this->presenter->crew($records),
            RecentItemType::Vessel => $this->presenter->vessels($records),
            RecentItemType::PayrollPeriod => $this->presenter->payroll($records),
        };
    }
}
