<?php

namespace App\Support\EmployeeTrainings;

use App\Models\EmployeeDocument;
use App\Models\EmployeeTraining;
use Illuminate\Support\Facades\DB;

class DeletesEmployeeTrainingRecord
{
    public function __construct(
        private TrainingCertificateDocumentIntegration $certificateDocuments,
        private StoresEmployeeTrainingCertificate $certificateStore,
    ) {}

    public function delete(EmployeeTraining $training): void
    {
        $companyId = (int) $training->company_id;
        $pathsToDelete = [];

        DB::transaction(function () use ($training, &$pathsToDelete): void {
            $trainingId = (int) $training->id;
            $companyId = (int) $training->company_id;
            $documentId = $training->employee_document_id;

            // Lock order: EmployeeDocument → EmployeeTraining when both are involved.
            if ($documentId !== null) {
                EmployeeDocument::query()
                    ->whereKey($documentId)
                    ->where('company_id', $companyId)
                    ->lockForUpdate()
                    ->first();
            }

            /** @var EmployeeTraining $lockedTraining */
            $lockedTraining = EmployeeTraining::query()
                ->whereKey($trainingId)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedTraining->loadMissing('versions');

            $candidatePaths = $this->certificateStore->resolveCertificatePaths($lockedTraining);

            $this->certificateDocuments->unlinkDocument($lockedTraining);

            $pathsToDelete = $this->certificateStore->filterDeletableCertificatePaths(
                $candidatePaths,
                $companyId,
                $trainingId,
            );

            $lockedTraining->delete();
        });

        if ($pathsToDelete === []) {
            return;
        }

        $paths = array_values(array_unique($pathsToDelete));

        DB::afterCommit(function () use ($paths, $companyId): void {
            $this->certificateStore->deletePaths($paths, $companyId);
        });
    }
}
