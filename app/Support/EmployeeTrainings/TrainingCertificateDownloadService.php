<?php

namespace App\Support\EmployeeTrainings;

use App\Models\Employee;
use App\Models\EmployeeTraining;
use App\Models\EmployeeTrainingVersion;
use App\Support\EmployeeFiles\EmployeePrivateFile;
use App\Support\EmployeeFiles\EmployeePrivateFileKind;
use Symfony\Component\HttpFoundation\Response;

class TrainingCertificateDownloadService
{
    public function downloadCurrent(
        Employee $employee,
        EmployeeTraining $training,
        int $companyId,
        bool $inline = false,
    ): Response {
        TrainingAccess::assertEmployeeInCompany($employee, $companyId, 404);
        TrainingAccess::assertTrainingBelongsToEmployee($employee, $training, $companyId, 404);
        TrainingAccess::assertTrainingInCompany($training, $companyId, 404);

        $path = (string) ($training->certificate_path ?? '');

        abort_if($path === '', 404, 'File not found.');

        return $this->respond(
            $path,
            $companyId,
            $this->sanitizeFilename(
                (string) ($training->certificate_original_filename ?: "training-{$training->id}-certificate"),
            ),
            (string) ($training->resolvedCertificateMimeType() ?: ($inline ? 'application/pdf' : 'application/octet-stream')),
            $inline,
        );
    }

    public function downloadVersion(
        Employee $employee,
        EmployeeTraining $training,
        EmployeeTrainingVersion $version,
        int $companyId,
        bool $inline = false,
    ): Response {
        TrainingAccess::assertEmployeeInCompany($employee, $companyId, 404);
        TrainingAccess::assertTrainingBelongsToEmployee($employee, $training, $companyId, 404);
        TrainingAccess::assertTrainingInCompany($training, $companyId, 404);
        TrainingAccess::assertVersionBelongsToTraining($training, $version, $companyId);

        return $this->respond(
            (string) $version->file_path,
            $companyId,
            $this->sanitizeFilename(
                (string) ($version->original_filename ?: "training-{$training->id}-v{$version->version}"),
            ),
            (string) ($version->mime_type ?: ($inline ? 'application/pdf' : 'application/octet-stream')),
            $inline,
        );
    }

    private function respond(
        string $filePath,
        int $companyId,
        string $downloadName,
        string $mimeType,
        bool $inline,
    ): Response {
        if (EmployeePrivateFile::isRemoteUrl($filePath)) {
            return redirect()->away($filePath);
        }

        $resolved = EmployeePrivateFile::resolve(
            $filePath,
            $companyId,
            EmployeePrivateFileKind::TrainingCertificate,
        );

        abort_if($resolved === null, 404, 'File not found.');

        if ($inline) {
            return $resolved->inlineResponse($downloadName, [
                'Content-Type' => $mimeType,
            ]);
        }

        return $resolved->download($downloadName, [
            'Content-Type' => $mimeType,
        ]);
    }

    private function sanitizeFilename(string $filename): string
    {
        $basename = basename($filename);
        $basename = preg_replace('/[^\w\.\-]+/u', '_', $basename) ?? 'certificate';
        $basename = trim($basename, '._');

        return $basename !== '' ? $basename : 'certificate';
    }
}
