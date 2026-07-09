<?php

namespace App\Support\BulkDocuments;

use App\Models\BulkDocumentSignatureRequest;
use App\Models\Employee;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Throwable;

final class StampSignedBulkDocumentPdf
{
    /**
     * @param  array{signed_name: string, signature_data: string, consent: bool}  $data
     */
    public function handle(BulkDocumentSignatureRequest $request, array $data): string
    {
        if (! BulkDocumentTypeRegistry::supportsEsignature($request->document_type_key)) {
            throw ValidationException::withMessages([
                'signature_data' => 'Electronic signing is not configured for this document type.',
            ]);
        }

        $request->loadMissing(['employee', 'employeeDocument']);

        $employee = $request->employee;

        if (! $employee instanceof Employee) {
            throw ValidationException::withMessages([
                'token' => 'The employee for this signing request is no longer available.',
            ]);
        }

        $this->assertValidSignatureImage($data['signature_data']);

        $signedDate = now()->format('d M Y');

        try {
            return BulkDocumentTypeRegistry::resolveRenderer($request->document_type_key)->render(
                $employee,
                $request->company_id,
                [
                    'signed_name' => $data['signed_name'],
                    'signature_image_url' => $data['signature_data'],
                    'signed_date' => $signedDate,
                ],
            );
        } catch (ProcessFailedException|Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'signature_data' => 'Unable to produce signed PDF. Please try again.',
            ]);
        }
    }

    private function assertValidSignatureImage(string $signatureData): void
    {
        if (! preg_match('#^data:image/(png|jpeg);base64,#i', $signatureData)) {
            throw ValidationException::withMessages([
                'signature_data' => 'A valid signature image is required.',
            ]);
        }

        $binary = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $signatureData) ?: '', true);

        if ($binary === false || strlen($binary) < 50) {
            throw ValidationException::withMessages([
                'signature_data' => 'A valid signature image is required.',
            ]);
        }
    }
}
