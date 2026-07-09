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

    protected $description = 'Show detected Browsershot binaries for salary declaration PDF generation';

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

        $this->components->info('Browsershot is ready for salary declaration PDF generation.');

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

            // #region agent log
            file_put_contents(
                base_path('.cursor/debug-9313b6.log'),
                json_encode([
                    'sessionId' => '9313b6',
                    'location' => 'BrowsershotDoctorCommand.php:smokeTestPdfGeneration',
                    'message' => 'Browsershot smoke test succeeded',
                    'data' => [
                        'pdfPrefix' => substr($pdf, 0, 8),
                        'pdfLength' => strlen($pdf),
                    ],
                    'timestamp' => (int) (microtime(true) * 1000),
                    'hypothesisId' => 'C',
                    'runId' => 'doctor-smoke',
                ]).PHP_EOL,
                FILE_APPEND,
            );
            // #endregion

            return str_starts_with($pdf, '%PDF');
        } catch (Throwable $exception) {
            // #region agent log
            file_put_contents(
                base_path('.cursor/debug-9313b6.log'),
                json_encode([
                    'sessionId' => '9313b6',
                    'location' => 'BrowsershotDoctorCommand.php:smokeTestPdfGeneration',
                    'message' => 'Browsershot smoke test failed',
                    'data' => [
                        'error' => $exception->getMessage(),
                        'chromePath' => ResolvesBrowsershotBinaries::chromePath(),
                        'home' => getenv('HOME') ?: null,
                    ],
                    'timestamp' => (int) (microtime(true) * 1000),
                    'hypothesisId' => 'A',
                    'runId' => 'doctor-smoke',
                ]).PHP_EOL,
                FILE_APPEND,
            );
            // #endregion

            $this->components->error($exception->getMessage());

            return false;
        }
    }
}
