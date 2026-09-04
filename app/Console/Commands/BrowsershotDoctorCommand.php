<?php

namespace App\Console\Commands;

use App\Support\BulkDocuments\ConfiguresBrowsershotEnvironment;
use App\Support\BulkDocuments\ConfiguresBrowsershotPdf;
use App\Support\BulkDocuments\ResolvesBrowsershotBinaries;
use Illuminate\Console\Command;
use Spatie\Browsershot\Browsershot;
use Throwable;

class BrowsershotDoctorCommand extends Command
{
    protected $signature = 'browsershot:doctor';

    protected $description = 'Show detected Browsershot binaries and smoke-test PDF generation plus layout evaluate()';

    public function handle(): int
    {
        $cacheDir = ConfiguresBrowsershotEnvironment::apply();

        $this->components->twoColumnDetail('Puppeteer cache', $cacheDir);
        $this->components->twoColumnDetail('HOME', getenv('HOME') ?: '(empty)');
        $this->components->twoColumnDetail('PATH', getenv('PATH') ?: '(empty)');

        $nodeBinary = ResolvesBrowsershotBinaries::nodeBinary();
        $npmBinary = ResolvesBrowsershotBinaries::npmBinary();
        $chromePath = ResolvesBrowsershotBinaries::chromePath();

        $this->components->twoColumnDetail('Node binary', $nodeBinary ?? 'not found');
        $this->components->twoColumnDetail('NPM binary', $npmBinary ?? 'not found');
        $this->components->twoColumnDetail('Chrome binary', $chromePath ?? 'not found');

        if ($nodeBinary === null || $npmBinary === null) {
            $this->components->error('Node.js is required for salary declaration PDF generation.');
            $this->line('On Hostinger, enable Node.js in hPanel, then set BROWSERSHOT_NODE_BINARY and BROWSERSHOT_NPM_BINARY if needed.');

            return self::FAILURE;
        }

        if ($chromePath === null) {
            $this->components->warn('Chrome headless shell is not installed. Run: php artisan browsershot:install');

            return self::FAILURE;
        }

        if (! $this->chromeBinaryIsExecutable($chromePath)) {
            $this->components->error("Chrome binary is not executable: {$chromePath}");
            $this->line('Run: php artisan browsershot:install');

            return self::FAILURE;
        }

        if (! $this->smokeTestPdfGeneration()) {
            $this->components->error('Chrome was found but failed to generate a test PDF.');
            $this->line('On shared hosting, confirm Node.js is enabled in hPanel, redeploy, then run:');
            $this->line('  php artisan browsershot:install');
            $this->line('  php artisan browsershot:doctor');
            $this->line('If it still fails, set BROWSERSHOT_CHROME_PATH to a system Chromium binary if your host provides one.');

            return self::FAILURE;
        }

        if (! $this->smokeTestLayoutEvaluation()) {
            $this->components->error('Chrome was found but failed the layout measurement evaluate() check used by PDF template validation.');
            $this->line('Run: php artisan browsershot:install && php artisan browsershot:doctor');

            return self::FAILURE;
        }

        $this->components->info('Browsershot is ready for salary declaration PDF generation and PDF overlay layout validation.');

        return self::SUCCESS;
    }

    private function chromeBinaryIsExecutable(string $chromePath): bool
    {
        return is_executable($chromePath);
    }

    private function smokeTestPdfGeneration(): bool
    {
        try {
            $shot = ConfiguresBrowsershotPdf::apply(
                Browsershot::html('<html><body><p>ok</p></body></html>'),
            );

            $pdf = $shot->pdf();

            return str_starts_with($pdf, '%PDF');
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return false;
        }
    }

    private function smokeTestLayoutEvaluation(): bool
    {
        try {
            $raw = ConfiguresBrowsershotPdf::apply(
                Browsershot::html('<html><body><div id="b">ok</div></body></html>'),
            )->evaluate('document.fonts.ready.then(function(){ return JSON.stringify({ok:true}); })');

            $decoded = json_decode(is_string($raw) ? $raw : '', true);

            if (is_string($decoded)) {
                $decoded = json_decode($decoded, true);
            }

            return is_array($decoded) && ($decoded['ok'] ?? false) === true;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return false;
        }
    }
}
