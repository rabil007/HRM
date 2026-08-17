<?php

namespace App\Support\Platform;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class PlatformDatabaseCatalog
{
    /**
     * Credential, session, cache, queue payload, and integration-secret tables.
     *
     * @var list<string>
     */
    private const DENIED_TABLES = [
        'cache',
        'cache_locks',
        'sessions',
        'password_reset_tokens',
        'jobs',
        'failed_jobs',
        'job_batches',
        'personal_access_tokens',
        'whatsapp_settings',
        'hikvision_settings',
        'sqlite_sequence',
    ];

    /**
     * @var list<string>
     */
    private const DENIED_TABLE_PREFIXES = [
        'pulse_',
        'telescope_',
        'horizon_',
        'sqlite_',
    ];

    public static function isAllowedTable(string $table): bool
    {
        $table = self::normalizeTable($table);

        if ($table === '' || preg_match('/^[a-z0-9_]+$/', $table) !== 1) {
            return false;
        }

        if (in_array($table, self::DENIED_TABLES, true)) {
            return false;
        }

        foreach (self::DENIED_TABLE_PREFIXES as $prefix) {
            if (str_starts_with($table, $prefix)) {
                return false;
            }
        }

        return Schema::hasTable($table);
    }

    public static function assertBrowsable(string $table): void
    {
        if (! self::isAllowedTable($table)) {
            abort(404);
        }
    }

    /**
     * @return list<string>
     */
    public static function listBrowsableTables(): array
    {
        /** @var list<string> $tables */
        $tables = Schema::getTableListing(schemaQualified: false);

        return collect($tables)
            ->map(fn (string $table): string => self::normalizeTable($table))
            ->filter(fn (string $table): bool => self::isAllowedTable($table))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public static function visibleColumns(string $table): array
    {
        return collect(Schema::getColumnListing($table))
            ->reject(fn (string $column): bool => self::isSecretColumn($table, $column))
            ->values()
            ->all();
    }

    public static function isSecretColumn(string $table, string $column): bool
    {
        $table = self::normalizeTable($table);
        $column = strtolower($column);

        if ($table === 'app_settings' && $column === 'value') {
            return true;
        }

        if (str_contains($column, 'two_factor') && $column !== 'two_factor_confirmed_at') {
            return true;
        }

        return preg_match(
            '/(^|_)(password|passwd|secret|token|payload|private_key|public_key|api_key|access_key|webhook_verify)(_|$)/',
            $column,
        ) === 1;
    }

    private static function normalizeTable(string $table): string
    {
        $table = strtolower(trim($table));

        if (str_contains($table, '.')) {
            $table = Str::afterLast($table, '.');
        }

        return $table;
    }
}
