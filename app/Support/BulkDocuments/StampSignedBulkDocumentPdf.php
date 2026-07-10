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
        // #region agent log
        @file_put_contents(base_path('.cursor/debug-46bd53.log'), json_encode(['sessionId' => '46bd53', 'runId' => 'verify', 'hypothesisId' => 'A', 'location' => 'StampSignedBulkDocumentPdf.php:handle:entry', 'message' => 'stamp signed pdf entry', 'data' => ['document_type_key' => $request->document_type_key, 'employee_id' => $request->employee_id, 'request_company_id' => $request->company_id, 'relation_employee_company_id' => $request->employee?->company_id, 'signature_data_length' => strlen($data['signature_data'])], 'timestamp' => (int) round(microtime(true) * 1000)])."\n", FILE_APPEND);
        // #endregion

        if (! BulkDocumentTypeRegistry::supportsEsignature($request->document_type_key)) {
            throw ValidationException::withMessages([
                'signature_data' => 'Electronic signing is not configured for this document type.',
            ]);
        }

        $request->loadMissing(['employeeDocument']);

        $employee = Employee::query()->find($request->employee_id);

        // #region agent log
        @file_put_contents(base_path('.cursor/debug-46bd53.log'), json_encode(['sessionId' => '46bd53', 'runId' => 'verify', 'hypothesisId' => 'A', 'location' => 'StampSignedBulkDocumentPdf.php:handle:employee-loaded', 'message' => 'full employee loaded', 'data' => ['employee_found' => $employee instanceof Employee, 'employee_company_id' => $employee instanceof Employee ? $employee->company_id : null, 'company_match' => $employee instanceof Employee ? ((int) $employee->company_id === (int) $request->company_id) : false], 'timestamp' => (int) round(microtime(true) * 1000)])."\n", FILE_APPEND);
        // #endregion

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
            $pdf = BulkDocumentTypeRegistry::resolveRenderer($request->document_type_key)->render(
                $employee,
                $request->company_id,
                [
                    'signed_name' => $data['signed_name'],
                    'signature_image_url' => $data['signature_data'],
                    'signed_date' => $signedDate,
                ],
            );

            // #region agent log
            @file_put_contents(base_path('.cursor/debug-46bd53.log'), json_encode(['sessionId' => '46bd53', 'runId' => 'verify', 'hypothesisId' => 'B', 'location' => 'StampSignedBulkDocumentPdf.php:renderViaTemplate:success', 'message' => 'template render succeeded', 'data' => ['pdf_length' => strlen($pdf), 'pdf_prefix' => substr($pdf, 0, 8)], 'timestamp' => (int) round(microtime(true) * 1000)])."\n", FILE_APPEND);
            // #endregion

            return $pdf;
        } catch (ProcessFailedException|Throwable $exception) {
            // #region agent log
            @file_put_contents(base_path('.cursor/debug-46bd53.log'), json_encode(['sessionId' => '46bd53', 'runId' => 'verify', 'hypothesisId' => 'B', 'location' => 'StampSignedBulkDocumentPdf.php:renderViaTemplate:catch', 'message' => 'template render failed', 'data' => ['exception_class' => $exception::class, 'exception_message' => $exception->getMessage(), 'exception_file' => $exception->getFile(), 'exception_line' => $exception->getLine()], 'timestamp' => (int) round(microtime(true) * 1000)])."\n", FILE_APPEND);
            // #endregion

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
