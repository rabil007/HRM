<?php

namespace App\Support\EmployeeTrainings;

use App\Models\EmployeeDocument;
use App\Models\EmployeeTraining;
use Illuminate\Database\Eloquent\Collection;

/**
 * Acquires certificate locks in the shared Document → Training order.
 *
 * Callers that already hold Training locks before entering
 * DeletesEmployeeTrainingRecord (or syncTrainingDatesToLinkedDocument) create
 * an AB-BA deadlock risk against Documents/signing paths. Use this helper to
 * lock linked documents first, then trainings, in stable id order.
 */
final class TrainingCertificateLockOrder
{
    /**
     * @param  list<int>  $trainingIds
     * @param  list<string>  $with
     * @return Collection<int, EmployeeTraining>
     */
    public static function lockDocumentsThenTrainings(
        int $companyId,
        array $trainingIds,
        array $with = [],
    ): Collection {
        $trainingIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $trainingIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($trainingIds === []) {
            return new Collection;
        }

        $documentIds = EmployeeTraining::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $trainingIds)
            ->whereNotNull('employee_document_id')
            ->orderBy('employee_document_id')
            ->pluck('employee_document_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($documentIds !== []) {
            EmployeeDocument::query()
                ->where('company_id', $companyId)
                ->whereIn('id', $documentIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
        }

        return EmployeeTraining::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $trainingIds)
            ->with($with)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  list<int>  $phaseIds
     * @param  list<string>  $with
     * @return Collection<int, EmployeeTraining>
     */
    public static function lockDocumentsThenTrainingsForSourcePhases(
        int $companyId,
        array $phaseIds,
        array $with = [],
    ): Collection {
        $phaseIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $phaseIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($phaseIds === []) {
            return new Collection;
        }

        $trainingIds = EmployeeTraining::query()
            ->where('company_id', $companyId)
            ->whereIn('source_crew_assignment_phase_id', $phaseIds)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return self::lockDocumentsThenTrainings($companyId, $trainingIds, $with);
    }
}
