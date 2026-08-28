<?php

namespace App\Support\Documents\RecipientRequests;

use App\Enums\DocumentRecipientAction;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentRecipientType;
use App\Models\DocumentRecipientRequest;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class DocumentRecipientRequestRosterQuery
{
    /**
     * @param  array{
     *     search?: string,
     *     status?: string,
     *     action?: string,
     *     assigned_to_me?: bool,
     * }  $filters
     * @return LengthAwarePaginator<int, DocumentRecipientRequest>
     */
    public function paginate(
        int $companyId,
        array $filters,
        int $perPage,
        int $page,
        ?User $viewer = null,
    ): LengthAwarePaginator {
        $query = DocumentRecipientRequest::query()
            ->forCompany($companyId)
            ->with([
                'requestedBy:id,name',
                'documentInstance.employeeDocument:id,title,employee_id',
                'employee:id,name,employee_no',
                'recipientUser:id,name',
                'sourceVersion:id,version',
                'resultVersion:id,version',
            ])
            ->orderByDesc('requested_at')
            ->orderByDesc('id');

        $this->applyFilters($query, $filters, $viewer, $companyId);

        return $query->paginate(
            perPage: max(1, min($perPage, 100)),
            page: max(1, $page),
        );
    }

    /**
     * @param  Builder<DocumentRecipientRequest>  $query
     * @param  array{search?: string, status?: string, action?: string, assigned_to_me?: bool}  $filters
     */
    private function applyFilters(
        Builder $query,
        array $filters,
        ?User $viewer,
        int $companyId,
    ): void {
        $assignedToMe = (bool) ($filters['assigned_to_me'] ?? false);

        if ($assignedToMe && $viewer !== null) {
            $query
                ->where('recipient_type', DocumentRecipientType::CompanyUser)
                ->where('recipient_user_id', $viewer->id);
        } elseif ($viewer !== null && ! $viewer->can('documents.recipient-requests.view')) {
            if ($viewer->can('documents.recipient-requests.respond')) {
                $query
                    ->where('recipient_type', DocumentRecipientType::CompanyUser)
                    ->where('recipient_user_id', $viewer->id);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

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
                    ->orWhereHas('recipientUser', fn (Builder $userQuery) => $userQuery
                        ->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('documentInstance.employeeDocument', fn (Builder $docQuery) => $docQuery
                        ->where('title', 'like', "%{$search}%"));
            });
        }
    }
}
