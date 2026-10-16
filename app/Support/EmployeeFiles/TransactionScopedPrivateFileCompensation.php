<?php

namespace App\Support\EmployeeFiles;

use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Tracks newly stored private files across nested DB transactions.
 *
 * Laravel's DB::afterRollBack() attaches to the current (innermost) transaction
 * record. When that inner transaction commits successfully, its rollback
 * callbacks are staged and then discarded if an outer transaction later rolls
 * back — so orphaned files remain on disk. This registry binds cleanup to the
 * enclosing connection transaction lifetime instead.
 *
 * Lock-order note for certificate flows lives on
 * TrainingCertificateDocumentIntegration::LOCK_ORDER (document → training).
 */
final class TransactionScopedPrivateFileCompensation
{
    /** @var array<string, list<array{path: string, company_id: int, level: int}>> */
    private static array $pending = [];

    private static mixed $boundDispatcher = null;

    public static function registerListeners(): void
    {
        $dispatcher = Event::getFacadeRoot();

        // Re-bind when the application/event dispatcher is rebuilt between tests.
        if (self::$boundDispatcher === $dispatcher) {
            return;
        }

        self::$boundDispatcher = $dispatcher;

        Event::listen(TransactionCommitted::class, [self::class, 'handleCommitted']);
        Event::listen(TransactionRolledBack::class, [self::class, 'handleRolledBack']);
    }

    public static function remember(string $path, int $companyId): void
    {
        if ($path === '' || $companyId <= 0) {
            return;
        }

        $level = DB::transactionLevel();

        if ($level === 0) {
            return;
        }

        $connection = (string) DB::connection()->getName();

        self::$pending[$connection][] = [
            'path' => $path,
            'company_id' => $companyId,
            'level' => $level,
        ];
    }

    public static function forget(string $path, ?string $connectionName = null): void
    {
        $connection = $connectionName ?? (string) DB::connection()->getName();

        if (! isset(self::$pending[$connection])) {
            return;
        }

        self::$pending[$connection] = array_values(array_filter(
            self::$pending[$connection],
            fn (array $item): bool => $item['path'] !== $path,
        ));

        if (self::$pending[$connection] === []) {
            unset(self::$pending[$connection]);
        }
    }

    public static function handleCommitted(TransactionCommitted $event): void
    {
        $connection = $event->connection;
        $name = (string) $connection->getName();

        if ($connection->transactionLevel() > 0) {
            return;
        }

        unset(self::$pending[$name]);
    }

    public static function handleRolledBack(TransactionRolledBack $event): void
    {
        $connection = $event->connection;
        $name = (string) $connection->getName();
        $remainingLevel = $connection->transactionLevel();

        if (! isset(self::$pending[$name])) {
            return;
        }

        $kept = [];

        foreach (self::$pending[$name] as $item) {
            if ($item['level'] > $remainingLevel) {
                EmployeePrivateFile::deleteCompanyOwnedFile(
                    $item['path'],
                    $item['company_id'],
                );

                continue;
            }

            $kept[] = $item;
        }

        if ($kept === []) {
            unset(self::$pending[$name]);

            return;
        }

        self::$pending[$name] = $kept;
    }

    /**
     * @return list<array{path: string, company_id: int, level: int}>
     */
    public static function pendingForConnection(?string $connectionName = null): array
    {
        $connection = $connectionName ?? (string) DB::connection()->getName();

        return self::$pending[$connection] ?? [];
    }

    public static function flushAll(): void
    {
        self::$pending = [];
    }
}
