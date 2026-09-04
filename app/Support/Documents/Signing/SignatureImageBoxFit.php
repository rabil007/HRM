<?php

namespace App\Support\Documents\Signing;

final class SignatureImageBoxFit
{
    /**
     * Fit an image inside a box (contain) and position it with the given alignment.
     *
     * @return array{0: float, 1: float, 2: float, 3: float} drawW, drawH, drawX, drawY
     */
    public static function contained(
        float $boxX,
        float $boxY,
        float $boxW,
        float $boxH,
        int $imgW,
        int $imgH,
        string $horizontalAlign = 'center',
        string $verticalAlign = 'middle',
    ): array {
        $scale = min($boxW / $imgW, $boxH / $imgH);
        $drawW = $imgW * $scale;
        $drawH = $imgH * $scale;

        $drawX = match ($horizontalAlign) {
            'left' => $boxX,
            'right' => $boxX + ($boxW - $drawW),
            default => $boxX + (($boxW - $drawW) / 2),
        };

        $drawY = match ($verticalAlign) {
            'top' => $boxY,
            'baseline' => $boxY + ($boxH - $drawH),
            default => $boxY + (($boxH - $drawH) / 2),
        };

        return [$drawW, $drawH, $drawX, $drawY];
    }
}
