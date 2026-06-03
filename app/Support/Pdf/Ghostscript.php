<?php

namespace App\Support\Pdf;

use Illuminate\Support\Facades\Process;
use Throwable;

final class Ghostscript
{
    /**
     * @return list<string>
     */
    public static function candidateBinaries(): array
    {
        $configured = trim((string) config('services.pdf.ghostscript_binary', 'gs'));

        return array_values(array_unique(array_filter([
            $configured !== '' ? $configured : null,
            'gs',
            '/opt/homebrew/bin/gs',
            '/usr/local/bin/gs',
            '/usr/bin/gs',
        ])));
    }

    public static function binary(): string
    {
        foreach (self::candidateBinaries() as $candidate) {
            if (self::isWorkingBinary($candidate)) {
                return $candidate;
            }
        }

        return (string) config('services.pdf.ghostscript_binary', 'gs');
    }

    public static function available(): bool
    {
        return self::isWorkingBinary(self::binary());
    }

    private static function isWorkingBinary(string $binary): bool
    {
        try {
            return Process::run([$binary, '--version'])->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
