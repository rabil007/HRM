<?php

namespace App\Support\Documents\RecipientRequests;

use App\Models\DocumentInstanceVersion;
use App\Support\Documents\DocumentInstanceStorage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\FpdiException;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use setasign\Fpdi\PdfParser\PdfParserException;

final class StampSignedDocumentInstancePdf
{
    /**
     * @param  array{id: string, type: string, role: string, page: int, x: float, y: float, width: float, height: float, required: bool}  $placement
     */
    public function handle(
        DocumentInstanceVersion $sourceVersion,
        string $signatureAbsolutePath,
        string $imageType,
        array $placement,
    ): string {
        $path = DocumentInstanceStorage::validatedRelativePath($sourceVersion->file_path, (int) $sourceVersion->company_id);

        if ($path === null) {
            throw ValidationException::withMessages([
                'signature_data' => 'The source document is unavailable.',
            ]);
        }

        $sourceAbsolute = Storage::disk(DocumentInstanceStorage::DISK)->path($path);

        if (! is_readable($sourceAbsolute)) {
            throw ValidationException::withMessages([
                'signature_data' => 'The source document is unavailable.',
            ]);
        }

        try {
            return $this->compose($sourceAbsolute, $signatureAbsolutePath, $imageType, $placement);
        } catch (CrossReferenceException|PdfParserException|FpdiException $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'signature_data' => 'Unable to produce signed PDF. Please try again.',
            ]);
        }
    }

    /**
     * @param  array{id: string, type: string, role: string, page: int, x: float, y: float, width: float, height: float, required: bool}  $placement
     */
    private function compose(
        string $sourcePath,
        string $signaturePath,
        string $imageType,
        array $placement,
    ): string {
        $pdf = new Fpdi;
        $pageCount = $pdf->setSourceFile($sourcePath);
        $targetPage = (int) $placement['page'];

        if ($targetPage < 1 || $targetPage > $pageCount) {
            throw ValidationException::withMessages([
                'signature_data' => 'Unable to apply signature placement.',
            ]);
        }

        for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
            $templateId = $pdf->importPage($pageNumber);
            $size = $pdf->getTemplateSize($templateId);
            $orientation = $size['orientation'];
            $width = (float) $size['width'];
            $height = (float) $size['height'];

            $pdf->AddPage($orientation, [$width, $height]);
            $pdf->useTemplate($templateId);

            if ($pageNumber === $targetPage) {
                $boxX = $placement['x'] * $width;
                $boxY = $placement['y'] * $height;
                $boxW = $placement['width'] * $width;
                $boxH = $placement['height'] * $height;

                [$drawW, $drawH, $drawX, $drawY] = $this->fitImageInBox(
                    $signaturePath,
                    $boxX,
                    $boxY,
                    $boxW,
                    $boxH,
                );

                $pdf->Image($signaturePath, $drawX, $drawY, $drawW, $drawH, $imageType);
            }
        }

        return $pdf->Output('S');
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function fitImageInBox(
        string $signaturePath,
        float $boxX,
        float $boxY,
        float $boxW,
        float $boxH,
    ): array {
        $imageInfo = @getimagesize($signaturePath);

        if ($imageInfo === false) {
            throw ValidationException::withMessages([
                'signature_data' => 'A valid signature image is required.',
            ]);
        }

        [$imgW, $imgH] = $imageInfo;

        if ($imgW <= 0 || $imgH <= 0 || $boxW <= 0 || $boxH <= 0) {
            throw ValidationException::withMessages([
                'signature_data' => 'Unable to apply signature placement.',
            ]);
        }

        $scale = min($boxW / $imgW, $boxH / $imgH);
        $drawW = $imgW * $scale;
        $drawH = $imgH * $scale;
        $drawX = $boxX + (($boxW - $drawW) / 2);
        $drawY = $boxY + (($boxH - $drawH) / 2);

        return [$drawW, $drawH, $drawX, $drawY];
    }
}
