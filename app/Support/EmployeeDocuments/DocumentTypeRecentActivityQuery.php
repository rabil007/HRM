<?php

namespace App\Support\EmployeeDocuments;

use App\Models\DocumentRequirement;
use App\Models\DocumentType;
use App\Models\User;
use App\Support\Activity\ActivityChangePresenter;
use Spatie\Activitylog\Models\Activity;

final class DocumentTypeRecentActivityQuery
{
    /**
     * @return list<array{
     *     id: int,
     *     event: string|null,
     *     description: string|null,
     *     causer: array{id: int, name: string, email: string}|null,
     *     old_values: mixed,
     *     new_values: mixed,
     *     created_at: mixed
     * }>
     */
    public static function for(
        ?User $user,
        int $companyId,
        DocumentType $documentType,
        int $limit = 20,
    ): array {
        if (! $user?->can('audit.view')) {
            return [];
        }

        $requirementIds = DocumentRequirement::query()
            ->forCompany($companyId)
            ->where('document_type_id', $documentType->id)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $logs = Activity::query()
            ->where('company_id', $companyId)
            ->where(function ($query) use ($documentType, $requirementIds): void {
                $query->where(function ($inner) use ($documentType): void {
                    $inner->where('subject_type', DocumentType::class)
                        ->where('subject_id', $documentType->id);
                });

                if ($requirementIds !== []) {
                    $query->orWhere(function ($inner) use ($requirementIds): void {
                        $inner->where('subject_type', DocumentRequirement::class)
                            ->whereIn('subject_id', $requirementIds);
                    });
                }
            })
            ->with(['causer:id,name,email'])
            ->latest('id')
            ->limit($limit)
            ->get();

        return ActivityChangePresenter::presentLogs($logs, $companyId)
            ->map(fn (Activity $log): array => ActivityChangePresenter::toRecentActivityArray($log))
            ->values()
            ->all();
    }
}
