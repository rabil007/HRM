<?php

namespace Tests\Unit\Services\Documents;

use Tests\TestCase;

class PdfOverlayTempFileCleanupTest extends TestCase
{
    public function test_renderer_deletes_partial_overlay_temp_files_when_browsershot_save_fails(): void
    {
        $probe = __DIR__.'/overlay_temp_cleanup_probe.php';
        $command = sprintf(
            'DB_CONNECTION=sqlite DB_DATABASE=:memory: APP_ENV=testing %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($probe),
        );

        exec($command, $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertContains('ok', $output);
    }
}
