<?php

namespace App\Support\BulkDocuments;

use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Models\BulkDocumentSignatureRequest;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SubmitBulkDocumentSignature
{
    public function __construct(
        private StampSignedBulkDocumentPdf $stampSignedPdf,
    ) {}

    /**
     * @param  array{signed_name: string, signature_data: string, consent: bool}  $data
     */
    public function handle(BulkDocumentSignatureRequest $request, array $data, ?string $ip, ?string $userAgent): BulkDocumentSignatureRequest
    {
        $this->assertSignable($request);

        if (! $data['consent']) {
            throw ValidationException::withMessages([
                'consent' => 'You must agree to sign this document electronically.',
            ]);
        }

        $signaturePath = $this->storeSignatureImage($request, $data['signature_data']);
        $pdfBinary = $this->stampSignedPdf->handle($request, $data);
        $signedPdfPath = $this->storeSignedPdf($request, $pdfBinary);

        $request->update([
            'status' => BulkDocumentSignatureRequestStatus::Submitted,
            'signed_name' => $data['signed_name'],
            'signature_image_path' => $signaturePath,
            'signed_pdf_path' => $signedPdfPath,
            'signed_at' => now(),
            'submitted_ip' => $ip,
            'user_agent' => $userAgent,
        ]);

        return $request->refresh();
    }

    private function assertSignable(BulkDocumentSignatureRequest $request): void
    {
        if ($request->status !== BulkDocumentSignatureRequestStatus::AwaitingSignature) {
            throw ValidationException::withMessages([
                'token' => 'This signing request is no longer available.',
            ]);
        }

        if ($request->isExpired()) {
            $request->update(['status' => BulkDocumentSignatureRequestStatus::Expired]);

            throw ValidationException::withMessages([
                'token' => 'This signing link has expired.',
            ]);
        }
    }

    private function storeSignatureImage(BulkDocumentSignatureRequest $request, string $signatureData): string
    {
        if (! preg_match('#^data:image/(png|jpeg);base64,#i', $signatureData, $matches)) {
            throw ValidationException::withMessages([
                'signature_data' => 'A valid signature image is required.',
            ]);
        }

        $extension = strtolower($matches[1]) === 'jpeg' ? 'jpg' : 'png';
        $binary = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $signatureData) ?: '', true);

        if ($binary === false || strlen($binary) < 50) {
            throw ValidationException::withMessages([
                'signature_data' => 'A valid signature image is required.',
            ]);
        }

        $path = sprintf(
            'bulk-document-signatures/%d/%d/%s.%s',
            $request->company_id,
            $request->employee_id,
            Str::uuid(),
            $extension,
        );

        Storage::disk('public')->put($path, $binary);

        return $path;
    }

    private function storeSignedPdf(BulkDocumentSignatureRequest $request, string $pdfBinary): string
    {
        $path = sprintf(
            'bulk-document-signatures/%d/%d/signed-%s.pdf',
            $request->company_id,
            $request->employee_id,
            Str::uuid(),
        );

        Storage::disk('public')->put($path, $pdfBinary);

        return $path;
    }
}
