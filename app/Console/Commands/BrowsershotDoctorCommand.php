<?php

namespace App\Console\Commands;

use App\Support\BulkDocuments\ConfiguresBrowsershotEnvironment;
use App\Support\BulkDocuments\ResolvesBrowsershotBinaries;
use Illuminate\Console\Command;

class BrowsershotDoctorCommand extends Command
{
    protected $signature = 'browsershot:doctor';

    protected $description = 'Show detected Browsershot binaries for salary declaration PDF generation';

    public function handle(): int
    {
        $cacheDir = ConfiguresBrowsershotEnvironment::apply();

        $this->components->twoColumnDetail('Puppeteer cache', $cacheDir);
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

        $this->components->info('Browsershot is ready for salary declaration PDF generation.');

        return self::SUCCESS;
    }
}
