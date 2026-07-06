<?php

namespace App\Console\Commands;

use App\Support\BulkDocuments\ConfiguresBrowsershotEnvironment;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class InstallBrowsershotCommand extends Command
{
    protected $signature = 'browsershot:install';

    protected $description = 'Install Puppeteer chrome-headless-shell for salary declaration PDF generation';

    public function handle(): int
    {
        $cacheDir = ConfiguresBrowsershotEnvironment::apply();

        $this->components->info("Using Puppeteer cache directory: {$cacheDir}");

        $npmBinary = config('services.browsershot.npm_binary');
        $command = is_string($npmBinary) && $npmBinary !== ''
            ? [$npmBinary, 'run', 'browsershot:install']
            : ['npm', 'run', 'browsershot:install'];

        $process = new Process(
            $command,
            base_path(),
            [
                'PUPPETEER_CACHE_DIR' => $cacheDir,
            ],
            null,
            600,
        );

        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        if (! $process->isSuccessful()) {
            $this->components->error('Browsershot install failed. Ensure Node.js and npm are available on this server.');

            return self::FAILURE;
        }

        $this->components->info('Browsershot chrome-headless-shell installed successfully.');

        return self::SUCCESS;
    }
}
