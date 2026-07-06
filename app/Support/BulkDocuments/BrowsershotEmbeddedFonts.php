<?php

namespace App\Support\BulkDocuments;

final class BrowsershotEmbeddedFonts
{
    /**
     * Inline @font-face rules for DejaVu fonts so headless Chrome can render
     * Latin and Arabic text on servers without system fonts installed.
     */
    public static function dejaVuStyles(): string
    {
        return once(function (): string {
            $fontDir = base_path('vendor/dompdf/dompdf/lib/fonts');

            $definitions = [
                ['DejaVu Serif', 'DejaVuSerif.ttf', 400, 'normal'],
                ['DejaVu Serif', 'DejaVuSerif-Bold.ttf', 700, 'normal'],
                ['DejaVu Sans', 'DejaVuSans.ttf', 400, 'normal'],
                ['DejaVu Sans', 'DejaVuSans-Bold.ttf', 700, 'normal'],
            ];

            $css = '';

            foreach ($definitions as [$family, $filename, $weight, $style]) {
                $path = $fontDir.'/'.$filename;

                if (! is_file($path)) {
                    continue;
                }

                $data = base64_encode((string) file_get_contents($path));

                $css .= "@font-face{font-family:'{$family}';src:url(data:font/truetype;charset=utf-8;base64,{$data}) format('truetype');font-weight:{$weight};font-style:{$style};}";
            }

            return $css;
        });
    }
}
