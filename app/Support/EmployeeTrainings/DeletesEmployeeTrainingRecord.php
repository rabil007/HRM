<?php

namespace App\Support\EmployeeTrainings;

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

            $training->loadMissing('versions');

            $candidatePaths = $this->certificateStore->resolveCertificatePaths($training);

            $this->certificateDocuments->unlinkDocument($training);

            $pathsToDelete = $this->certificateStore->filterDeletableCertificatePaths(
                $candidatePaths,
                $companyId,
                $trainingId,
            );

            $training->delete();
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
