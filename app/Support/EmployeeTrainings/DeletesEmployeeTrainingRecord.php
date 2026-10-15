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
        DB::transaction(function () use ($training): void {
            $hadIntegratedDocument = $training->employee_document_id !== null;

            $this->certificateDocuments->unlinkDocument($training);

            if (! $hadIntegratedDocument) {
                $this->certificateStore->deleteLegacyCertificateFilesForTraining($training);
            }

            $training->delete();
        });
    }
}
