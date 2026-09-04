<?php

namespace App\Support\Documents;

use App\Support\BulkDocuments\ConfiguresBrowsershotPdf;
use Spatie\Browsershot\Browsershot;
use Throwable;

class PdfOverlayLayoutMeasurementClient
{
    /**
     * @throws \RuntimeException
     */
    public function evaluateHtml(string $html, string $pageFunction): string
    {
        try {
            $raw = ConfiguresBrowsershotPdf::apply(
                Browsershot::html($html),
            )->evaluate($pageFunction);
        } catch (Throwable $e) {
            throw new \RuntimeException('PDF overlay layout measurement failed.', 0, $e);
        }

        return is_string($raw) ? $raw : '';
    }
}
