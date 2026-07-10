<?php

namespace App\Support\BulkDocuments;

use App\Models\BulkDocumentSignatureRequest;
use App\Models\Employee;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\FpdiException;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use setasign\Fpdi\PdfParser\PdfParserException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Throwable;

final class StampSignedBulkDocumentPdf
{
    /**
     * @param  array{signed_name: string, signature_data: string, consent: bool}  $data
     */
    public function handle(BulkDocumentSignatureRequest $request, array $data, ?string $signedDate = null): string
    {
        if (! BulkDocumentTypeRegistry::supportsEsignature($request->document_type_key)) {
            throw ValidationException::withMessages([
                'signature_data' => 'Electronic signing is not configured for this document type.',
            ]);
        }

        $request->loadMissing(['employeeDocument']);

        $employee = Employee::query()->find($request->employee_id);

        if (! $employee instanceof Employee) {
            throw ValidationException::withMessages([
                'token' => 'The employee for this signing request is no longer available.',
            ]);
        }

        [$signatureBinary, $imageType] = $this->decodeSignatureImage($data['signature_data']);
        $signedDate ??= now()->format('d M Y');

        if ($this->shouldRenderInlineSignature($request)) {
            return $this->renderViaTemplate($request, $employee, $data, $signedDate);
        }

        if ($this->canStampOntoSourcePdf($request)) {
            try {
                return $this->stampOntoSourcePdf($request, $signatureBinary, $imageType, $signedDate);
            } catch (CrossReferenceException|PdfParserException|FpdiException $exception) {
                report($exception);
            }
        }

        return $this->renderViaTemplate($request, $employee, $data, $signedDate);
    }

    private function shouldRenderInlineSignature(BulkDocumentSignatureRequest $request): bool
    {
        return $request->document_type_key === 'salary_declaration';
    }

    /**
     * @param  array{signed_name: string, signature_data: string, consent: bool}  $data
     */
    private function renderViaTemplate(
        BulkDocumentSignatureRequest $request,
        Employee $employee,
        array $data,
        string $signedDate,
    ): string {
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

    private function canStampOntoSourcePdf(BulkDocumentSignatureRequest $request): bool
    {
        if (BulkDocumentTypeRegistry::resolveSignaturePlacements($request->document_type_key) === null) {
            return false;
        }

        $document = $request->employeeDocument;

        if ($document === null || $document->file_path === null) {
            return false;
        }

        return Storage::disk('public')->exists($document->file_path);
    }

    private function stampOntoSourcePdf(
        BulkDocumentSignatureRequest $request,
        string $signatureBinary,
        string $imageType,
        string $signedDate,
    ): string {
        $placements = BulkDocumentTypeRegistry::resolveSignaturePlacements($request->document_type_key);

        if ($placements === null) {
            throw ValidationException::withMessages([
                'signature_data' => 'Unable to produce signed PDF. Please try again.',
            ]);
        }

        $document = $request->employeeDocument;

        if ($document === null || $document->file_path === null) {
            throw ValidationException::withMessages([
                'token' => 'The source document is no longer available.',
            ]);
        }

        if (! Storage::disk('public')->exists($document->file_path)) {
            throw ValidationException::withMessages([
                'token' => 'The source document file could not be found.',
            ]);
        }

        $signatureTempPath = $this->writeTempSignature($signatureBinary, $imageType);
        $sourcePath = Storage::disk('public')->path($document->file_path);

        try {
            return $this->stamp($sourcePath, $signatureTempPath, $imageType, $signedDate, $placements);
        } finally {
            @unlink($signatureTempPath);
        }
    }

    /**
     * @param  array{
     *     page: int,
     *     overlay: array{left: string, top: string, width: string, height: string},
     *     stamps: list<array{type: string, x: float, y: float, w?: float, h?: float}>
     * }  $placements
     */
    private function stamp(
        string $sourcePath,
        string $signatureTempPath,
        string $imageType,
        string $signedDate,
        array $placements,
    ): string {
        $pdf = new Fpdi;
        $pageNumber = $placements['page'];
        $pageCount = $pdf->setSourceFile($sourcePath);

        if ($pageNumber < 1 || $pageNumber > $pageCount) {
            throw ValidationException::withMessages([
                'signature_data' => 'Unable to produce signed PDF. Please try again.',
            ]);
        }

        $templateId = $pdf->importPage($pageNumber);
        $size = $pdf->getTemplateSize($templateId);

        $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
        $pdf->useTemplate($templateId);

        foreach ($placements['stamps'] as $stamp) {
            if ($stamp['type'] === 'image') {
                $pdf->Image(
                    $signatureTempPath,
                    $stamp['x'],
                    $stamp['y'],
                    $stamp['w'] ?? 0,
                    $stamp['h'] ?? 0,
                    $imageType,
                );

                continue;
            }

            if ($stamp['type'] === 'date') {
                $boxHeight = (float) ($stamp['h'] ?? 6.0);
                $textTop = $stamp['y'] - $boxHeight;

                $pdf->SetFont('Helvetica', '', 10);
                $pdf->SetXY($stamp['x'], $textTop);
                $pdf->Cell(40, $boxHeight, $signedDate, 0, 0, 'L');
            }
        }

        return $pdf->Output('S');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function decodeSignatureImage(string $signatureData): array
    {
        if (! preg_match('#^data:image/(png|jpeg);base64,#i', $signatureData, $matches)) {
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

        $imageType = strtolower($matches[1]) === 'jpeg' ? 'JPEG' : 'PNG';

        return [$binary, $imageType];
    }

    private function writeTempSignature(string $binary, string $imageType): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'esign_sig_');

        if ($tempPath === false) {
            throw ValidationException::withMessages([
                'signature_data' => 'Could not process signature image.',
            ]);
        }

        $extension = $imageType === 'JPEG' ? '.jpg' : '.png';
        $finalPath = $tempPath.$extension;
        rename($tempPath, $finalPath);
        file_put_contents($finalPath, $binary);

        return $finalPath;
    }
}
