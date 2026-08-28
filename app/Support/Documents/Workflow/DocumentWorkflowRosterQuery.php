<?php

namespace App\Support\Documents\Workflow;

use App\Enums\DocumentWorkflowAction;
use App\Enums\DocumentWorkflowRequestStatus;
use App\Enums\DocumentWorkflowStageStatus;
use App\Enums\DocumentWorkflowTaskStatus;
use App\Models\DocumentWorkflowRequest;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class DocumentWorkflowRosterQuery
{
    /**
     * @param  array{
     *     search?: string,
     *     status?: string,
     *     action?: string,
     *     assigned_to_me?: bool,
     * }  $filters
     * @return LengthAwarePaginator<int, DocumentWorkflowRequest>
     */
    public function paginate(
        int $companyId,
        array $filters,
        int $perPage,
        int $page,
        ?User $viewer = null,
    ): LengthAwarePaginator {
        $query = DocumentWorkflowRequest::query()
            ->forCompany($companyId)
            ->with([
                'requester:id,name',
                'documentInstance.employeeDocument.employee:id,name,employee_no',
                'documentInstance.employeeDocument:id,title,employee_id',
                'stages' => fn ($q) => $q->orderBy('sequence'),
                'stages.tasks',
            ])
            ->orderByDesc('requested_at')
            ->orderByDesc('id');

        $this->applyFilters($query, $filters, $viewer);

        return $query->paginate(
            perPage: max(1, min($perPage, 100)),
            page: max(1, $page),
        );
    }

    /**
     * @param  Builder<DocumentWorkflowRequest>  $query
     * @param  array{
     *     search?: string,
     *     status?: string,
     *     action?: string,
     *     assigned_to_me?: bool,
     * }  $filters
     */
    private function applyFilters(Builder $query, array $filters, ?User $viewer): void
    {
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && in_array($status, DocumentWorkflowRequestStatus::values(), true)) {
            $query->where('status', $status);
        }

        $action = trim((string) ($filters['action'] ?? ''));
        if ($action !== '' && in_array($action, DocumentWorkflowAction::values(), true)) {
            $query->whereHas('stages', function (Builder $stageQuery) use ($action): void {
                $stageQuery
                    ->where('action', $action)
                    ->where('status', DocumentWorkflowStageStatus::Active);
            });
        }

        if (($filters['assigned_to_me'] ?? false) && $viewer !== null) {
            $query->whereHas('stages.tasks', function (Builder $taskQuery) use ($viewer): void {
                $taskQuery
                    ->where('assignee_user_id', $viewer->id)
                    ->where('status', DocumentWorkflowTaskStatus::Pending)
                    ->whereHas('stage', fn (Builder $stageQuery) => $stageQuery->where('status', DocumentWorkflowStageStatus::Active));
            });
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('requester_name_snapshot', 'like', "%{$search}%")
                    ->orWhereHas('documentInstance.employeeDocument', function (Builder $docQuery) use ($search): void {
                        $docQuery->where('title', 'like', "%{$search}%");
                    })
                    ->orWhereHas('documentInstance.employeeDocument.employee', function (Builder $employeeQuery) use ($search): void {
                        $employeeQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('employee_no', 'like', "%{$search}%");
                    });
            });
        }
    }
}
