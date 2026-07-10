<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use setasign\Fpdi\Fpdi;

class AnalyzeSignedSalaryDeclarationPdfsCommand extends Command
{
    protected $signature = 'debug:analyze-signed-salary-pdfs {paths* : Absolute paths to signed PDF files}';

    protected $description = 'Analyze signed salary declaration PDF stamp positions and name layout';

    public function handle(): int
    {
        foreach ($this->argument('paths') as $path) {
            if (! is_string($path) || ! is_readable($path)) {
                $this->error("Unreadable file: {$path}");

                continue;
            }

            $analysis = $this->analyzePdf($path);
            $this->line(json_encode($analysis, JSON_PRETTY_PRINT));

            // #region agent log
            $this->debugLog('pdf_alignment_analysis', 'H1', $analysis);
            // #endregion
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function analyzePdf(string $path): array
    {
        $pdf = new Fpdi;
        $pdf->setSourceFile($path);
        $size = $pdf->getTemplateSize($pdf->importPage(1));

        $raw = file_get_contents($path) ?: '';
        $text = $this->extractPlainText($raw);
        $stampImages = $this->extractStampImages($raw);
        $signBlockNameLines = $this->countSignBlockNameLines($text);

        return [
            'file' => basename($path),
            'page_mm' => [
                'width' => round((float) $size['width'], 2),
                'height' => round((float) $size['height'], 2),
            ],
            'stamp_images_pt' => $stampImages,
            'sign_block_name_line_count' => $signBlockNameLines,
            'employee_name' => $this->extractEmployeeName($text),
            'configured_stamp_mm' => [
                'signature_en' => ['x' => 24.0, 'y' => 248.0, 'w' => 68.0, 'h' => 14.0],
                'signature_ar' => ['x' => 118.0, 'y' => 248.0, 'w' => 68.0, 'h' => 14.0],
                'date_en' => ['x' => 24.0, 'y' => 268.0],
                'date_ar' => ['x' => 118.0, 'y' => 268.0],
            ],
        ];
    }

    private function extractPlainText(string $raw): string
    {
        $text = '';

        if (! preg_match_all("/stream\r?\n(.*?)\r?\nendstream/s", $raw, $streams)) {
            return $text;
        }

        foreach ($streams[1] as $stream) {
            $decoded = @gzuncompress($stream);

            if ($decoded === false) {
                $decoded = @gzdecode($stream);
            }

            if ($decoded === false) {
                $decoded = $stream;
            }

            if (preg_match_all('/\((.*?)\)\s*Tj/s', $decoded, $matches)) {
                foreach ($matches[1] as $fragment) {
                    $text .= stripcslashes($fragment)."\n";
                }
            }
        }

        return $text;
    }

    /**
     * @return list<array{x_pt: float, y_pt: float, w_pt: float, h_pt: float}>
     */
    private function extractStampImages(string $raw): array
    {
        $images = [];

        if (! preg_match_all("/stream\r?\n(.*?)\r?\nendstream/s", $raw, $streams)) {
            return $images;
        }

        foreach ($streams[1] as $stream) {
            $decoded = @gzuncompress($stream);

            if ($decoded === false) {
                $decoded = @gzdecode($stream);
            }

            if ($decoded === false) {
                $decoded = $stream;
            }

            if (! preg_match_all('/([0-9.]+)\s+0\s+0\s+([0-9.]+)\s+([0-9.]+)\s+([0-9.]+)\s+cm/', $decoded, $matches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $match) {
                $width = (float) $match[1];
                $height = (float) $match[2];

                if ($width < 50) {
                    continue;
                }

                $images[] = [
                    'x_pt' => (float) $match[3],
                    'y_pt' => (float) $match[4],
                    'w_pt' => $width,
                    'h_pt' => $height,
                ];
            }
        }

        return $images;
    }

    private function countSignBlockNameLines(string $text): int
    {
        if (! preg_match('/Employee Name:\s*(.*?)\s*Signature:/s', $text, $match)) {
            return 0;
        }

        $block = trim(preg_replace('/\s+/', ' ', $match[1]) ?? '');

        if ($block === '') {
            return 0;
        }

        return substr_count($block, ' ') + 1;
    }

    private function extractEmployeeName(string $text): ?string
    {
        if (! preg_match('/Employee Name:\s*(.*?)\s*Signature:/s', $text, $match)) {
            return null;
        }

        return trim(preg_replace('/\s+/', ' ', $match[1]) ?? '') ?: null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function debugLog(string $message, string $hypothesisId, array $data): void
    {
        $payload = json_encode([
            'sessionId' => '351a82',
            'runId' => 'pdf-analysis',
            'hypothesisId' => $hypothesisId,
            'location' => 'AnalyzeSignedSalaryDeclarationPdfsCommand.php',
            'message' => $message,
            'data' => $data,
            'timestamp' => (int) (microtime(true) * 1000),
        ], JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return;
        }

        @file_put_contents(base_path('.cursor/debug-351a82.log'), $payload."\n", FILE_APPEND);
    }
}
