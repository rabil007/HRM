<?php

namespace App\Support\BulkDocuments;

use App\Models\BulkDocumentSignatureRequest;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\FpdiException;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use setasign\Fpdi\PdfParser\PdfParserException;

final class StampSignedBulkDocumentPdf
{
    /**
     * @param  array{signed_name: string, signature_data: string, consent: bool}  $data
     */
    public function handle(BulkDocumentSignatureRequest $request, array $data): string
    {
        $placements = BulkDocumentTypeRegistry::resolveSignaturePlacements($request->document_type_key);

        if ($placements === null) {
            throw ValidationException::withMessages([
                'signature_data' => 'Electronic signing is not configured for this document type.',
            ]);
        }

        $request->loadMissing('employeeDocument');
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

        $signatureBinary = $this->decodeSignatureImage($data['signature_data']);
        $signatureTempPath = $this->writeTempSignature($signatureBinary);
        $sourcePath = Storage::disk('public')->path($document->file_path);
        $signedDate = now()->format('d M Y');

        try {
            return $this->stamp($sourcePath, $signatureTempPath, $signedDate, $placements);
        } catch (CrossReferenceException|PdfParserException|FpdiException $exception) {
            throw ValidationException::withMessages([
                'signature_data' => 'Unable to produce signed PDF: '.$exception->getMessage(),
            ]);
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
        string $signedDate,
        array $placements,
    ): string {
        $pdf = new Fpdi;
        $pageNumber = $placements['page'];
        $pageCount = $pdf->setSourceFile($sourcePath);

        if ($pageNumber < 1 || $pageNumber > $pageCount) {
            throw ValidationException::withMessages([
                'signature_data' => 'The source document does not contain the expected signature page.',
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
                    'PNG',
                );

                continue;
            }

            if ($stamp['type'] === 'date') {
                $pdf->SetFont('Helvetica', '', 10);
                $pdf->Text($stamp['x'], $stamp['y'], $signedDate);
            }
        }

        return $pdf->Output('S');
    }

    private function decodeSignatureImage(string $signatureData): string
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

        return $binary;
    }

    private function writeTempSignature(string $binary): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'esign_sig_');

        if ($tempPath === false) {
            throw ValidationException::withMessages([
                'signature_data' => 'Could not process signature image.',
            ]);
        }

        $pngPath = $tempPath.'.png';
        rename($tempPath, $pngPath);
        file_put_contents($pngPath, $binary);

        return $pngPath;
    }
}
