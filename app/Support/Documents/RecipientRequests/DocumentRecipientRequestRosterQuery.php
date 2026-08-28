<?php

namespace App\Support\Documents\RecipientRequests;

use App\Enums\DocumentRecipientAction;
use App\Enums\DocumentRecipientRequestStatus;
use App\Models\DocumentRecipientRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class DocumentRecipientRequestRosterQuery
{
    /**
     * @param  array{
     *     search?: string,
     *     status?: string,
     *     action?: string,
     * }  $filters
     * @return LengthAwarePaginator<int, DocumentRecipientRequest>
     */
    public function paginate(
        int $companyId,
        array $filters,
        int $perPage,
        int $page,
    ): LengthAwarePaginator {
        $query = DocumentRecipientRequest::query()
            ->forCompany($companyId)
            ->with([
                'requestedBy:id,name',
                'documentInstance.employeeDocument:id,title,employee_id',
                'employee:id,name,employee_no',
            ])
            ->orderByDesc('requested_at')
            ->orderByDesc('id');

        $this->applyFilters($query, $filters);

        return $query->paginate(
            perPage: max(1, min($perPage, 100)),
            page: max(1, $page),
        );
    }

    /**
     * @param  Builder<DocumentRecipientRequest>  $query
     * @param  array{search?: string, status?: string, action?: string}  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $status = trim((string) ($filters['status'] ?? ''));

        if ($status !== '' && in_array($status, DocumentRecipientRequestStatus::values(), true)) {
            $query->where('status', $status);
        }

        $action = trim((string) ($filters['action'] ?? ''));

        if ($action !== '' && in_array($action, DocumentRecipientAction::values(), true)) {
            $query->where('action', $action);
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('recipient_name_snapshot', 'like', "%{$search}%")
                    ->orWhereHas('employee', fn (Builder $employeeQuery) => $employeeQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('employee_no', 'like', "%{$search}%"))
                    ->orWhereHas('documentInstance.employeeDocument', fn (Builder $docQuery) => $docQuery
                        ->where('title', 'like', "%{$search}%"));
            });
        }
    }
}
