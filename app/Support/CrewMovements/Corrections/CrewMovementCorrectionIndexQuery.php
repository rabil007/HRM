<?php

namespace App\Support\CrewMovements\Corrections;

use App\Enums\CrewMovementCorrectionStatus;
use App\Models\Company;
use App\Models\CrewMovementCorrection;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

final class CrewMovementCorrectionIndexQuery
{
    public function __construct(
        private readonly int $companyId,
        private readonly ?User $user,
        private readonly string $status = '',
        private readonly string $search = '',
        private readonly string $scope = '',
        private readonly string $slaStatus = '',
        private readonly string $timezone = 'UTC',
        private readonly CrewMovementCorrectionSla $sla = new CrewMovementCorrectionSla,
    ) {}

    public static function fromRequest(Request $request, int $companyId): self
    {
        return new self(
            companyId: $companyId,
            user: $request->user(),
            status: trim((string) $request->query('status', '')),
            search: trim((string) $request->query('search', '')),
            scope: trim((string) $request->query('scope', '')),
            slaStatus: trim((string) $request->query('sla_status', '')),
            timezone: (string) (Company::query()->whereKey($companyId)->value('timezone')
                ?? config('app.timezone', 'UTC')),
        );
    }

    /**
     * @return LengthAwarePaginator<int, CrewMovementCorrection>
     */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        $query = $this->baseQuery()
            ->when($this->status !== '', fn (Builder $query) => $query->where('status', $this->status))
            ->when($this->scope === 'my_requests' && $this->user !== null, fn (Builder $query) => $query->where('requested_by', $this->user->id))
            ->when($this->search !== '', function (Builder $query): void {
                $search = $this->search;
                $query->where(function (Builder $inner) use ($search): void {
                    $inner->whereHas('assignment', function (Builder $assignmentQuery) use ($search): void {
                        $assignmentQuery->where('assignment_no', 'like', "%{$search}%")
                            ->orWhereHas('employee', function (Builder $employeeQuery) use ($search): void {
                                $employeeQuery->where('name', 'like', "%{$search}%")
                                    ->orWhere('employee_no', 'like', "%{$search}%");
                            });
                    });
                });
            });

        if (in_array($this->slaStatus, ['normal', 'attention', 'overdue'], true)) {
            $this->sla->applyFilter($query, $this->slaStatus, $this->timezone);
        }

        $this->sla->applyPriorityOrder($query, $this->timezone);

        return $query
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return array{pending: int, approved: int, rejected: int, cancelled: int, my_requests: int, all: int}
     */
    public function statusCounts(): array
    {
        $base = CrewMovementCorrection::query()->where('company_id', $this->companyId);
        $counts = (clone $base)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $all = array_sum(array_map('intval', $counts));

        return [
            'all' => $all,
            'pending' => (int) ($counts[CrewMovementCorrectionStatus::Pending->value] ?? 0),
            'approved' => (int) ($counts[CrewMovementCorrectionStatus::Approved->value] ?? 0),
            'rejected' => (int) ($counts[CrewMovementCorrectionStatus::Rejected->value] ?? 0),
            'cancelled' => (int) ($counts[CrewMovementCorrectionStatus::Cancelled->value] ?? 0),
            'my_requests' => $this->user === null
                ? 0
                : (clone $base)->where('requested_by', $this->user->id)->count(),
        ];
    }

    public function summaryCounts(): array
    {
        $base = CrewMovementCorrection::query()
            ->where('company_id', $this->companyId);
        $pendingCounts = $this->sla->pendingCounts(clone $base, $this->timezone);

        return [
            ...$pendingCounts,
            'my_requests' => $this->user === null
                ? 0
                : (clone $base)->where('requested_by', $this->user->id)->count(),
        ];
    }

    /**
     * @return Builder<CrewMovementCorrection>
     */
    private function baseQuery(): Builder
    {
        return CrewMovementCorrection::query()
            ->with([
                'company:id,timezone',
                'assignment.employee:id,company_id,employee_no,name',
                'assignment.vessel:id,name',
                'phase:id,crew_assignment_id,phase_code,status',
                'requester:id,name',
                'decisionMaker:id,name',
            ])
            ->where('company_id', $this->companyId);
    }
}
