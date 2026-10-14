<?php

namespace App\Support\CrewOperations\Reset;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CrewOperationsResetEnvironmentGuard
{
    public function assertMayRun(): void
    {
        $environment = (string) app()->environment();

        if (in_array($environment, ['local', 'development'], true)) {
            $this->assertDatabaseIdentity();

            return;
        }

        if ($environment === 'testing' && config('crew_operations_reset.allow_in_testing') === true) {
            $driver = (string) config('database.default');
            $connection = config("database.connections.{$driver}.driver");

            if ($connection === 'sqlite') {
                return;
            }

            $this->assertDatabaseIdentity();

            return;
        }

        throw new RuntimeException(
            'The crew:reset command is only available in local or development environments.',
        );
    }

    private function assertDatabaseIdentity(): void
    {
        $driver = (string) config('database.default');
        $connection = config("database.connections.{$driver}");

        $database = (string) ($connection['database'] ?? '');
        $host = (string) ($connection['host'] ?? '');

        $allowedDatabases = config('crew_operations_reset.allowed_databases', ['oms_hrm']);
        $allowedHosts = config('crew_operations_reset.allowed_hosts', ['127.0.0.1', 'localhost', '::1']);

        if (! in_array($database, $allowedDatabases, true)) {
            throw new RuntimeException(
                "Refusing crew:reset on database [{$database}]. Expected one of: ".implode(', ', $allowedDatabases).'.',
            );
        }

        if ($host !== '' && ! in_array($host, $allowedHosts, true)) {
            throw new RuntimeException(
                "Refusing crew:reset on database host [{$host}]. Expected one of: ".implode(', ', $allowedHosts).'.',
            );
        }

        $liveDatabase = (string) DB::connection()->getDatabaseName();
        if ($liveDatabase !== '' && $liveDatabase !== $database) {
            throw new RuntimeException(
                "Database identity mismatch: configured [{$database}] but connected to [{$liveDatabase}].",
            );
        }
    }
}
