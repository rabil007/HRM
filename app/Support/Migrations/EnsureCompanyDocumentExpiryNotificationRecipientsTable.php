<?php

namespace App\Support\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class EnsureCompanyDocumentExpiryNotificationRecipientsTable
{
    public const TABLE = 'company_document_expiry_notification_recipients';

    public const UNIQUE_INDEX = 'cdnr_setting_user_type_unique';

    public const TYPE_INDEX = 'cdnr_setting_type_index';

    public const SETTING_FOREIGN = 'cdnr_setting_id_foreign';

    public const USER_FOREIGN = 'company_document_expiry_notification_recipients_user_id_foreign';

    public static function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            self::createTable();

            return;
        }

        self::ensureRequiredColumns();
        self::assertNoOrphanedReferences();
        self::deduplicateRecipients();
        self::ensureIndexes();
        self::ensureForeignKeys();
        self::assertSchemaIntegrity();
    }

    public static function hasRows(): bool
    {
        if (! Schema::hasTable(self::TABLE)) {
            return false;
        }

        return DB::table(self::TABLE)->exists();
    }

    private static function createTable(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('setting_id')
                ->constrained('company_document_expiry_notification_settings', 'id', self::SETTING_FOREIGN)
                ->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('to');
            $table->timestamps();

            $table->unique(['setting_id', 'user_id', 'type'], self::UNIQUE_INDEX);
            $table->index(['setting_id', 'type'], self::TYPE_INDEX);
        });
    }

    private static function ensureRequiredColumns(): void
    {
        $requiredCoreColumns = ['id', 'setting_id', 'user_id', 'type'];
        $missingCoreColumns = array_values(array_filter(
            $requiredCoreColumns,
            fn (string $column): bool => ! Schema::hasColumn(self::TABLE, $column),
        ));

        if ($missingCoreColumns !== []) {
            if (self::hasRows()) {
                throw new RuntimeException(sprintf(
                    'Cannot safely repair [%s]: existing rows are present but required columns are missing [%s]. Existing data was preserved.',
                    self::TABLE,
                    implode(', ', $missingCoreColumns),
                ));
            }

            Schema::drop(self::TABLE);
            self::createTable();

            return;
        }

        $missingCreatedAt = ! Schema::hasColumn(self::TABLE, 'created_at');
        $missingUpdatedAt = ! Schema::hasColumn(self::TABLE, 'updated_at');

        if (! $missingCreatedAt && ! $missingUpdatedAt) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($missingCreatedAt, $missingUpdatedAt): void {
            if ($missingCreatedAt) {
                $table->timestamp('created_at')->nullable();
            }

            if ($missingUpdatedAt) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    private static function assertNoOrphanedReferences(): void
    {
        $orphanedSettings = DB::table(self::TABLE.' as recipients')
            ->leftJoin(
                'company_document_expiry_notification_settings as settings',
                'settings.id',
                '=',
                'recipients.setting_id',
            )
            ->whereNull('settings.id')
            ->count();

        if ($orphanedSettings > 0) {
            throw new RuntimeException(sprintf(
                'Cannot add recipient foreign keys: [%s] contains %d orphaned setting reference(s). Existing rows were preserved.',
                self::TABLE,
                $orphanedSettings,
            ));
        }

        $orphanedUsers = DB::table(self::TABLE.' as recipients')
            ->leftJoin('users', 'users.id', '=', 'recipients.user_id')
            ->whereNull('users.id')
            ->count();

        if ($orphanedUsers > 0) {
            throw new RuntimeException(sprintf(
                'Cannot add recipient foreign keys: [%s] contains %d orphaned user reference(s). Existing rows were preserved.',
                self::TABLE,
                $orphanedUsers,
            ));
        }
    }

    private static function deduplicateRecipients(): void
    {
        $duplicates = DB::table(self::TABLE)
            ->select([
                'setting_id',
                'user_id',
                'type',
                DB::raw('MIN(id) as keep_id'),
            ])
            ->groupBy('setting_id', 'user_id', 'type')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            DB::table(self::TABLE)
                ->where('setting_id', $duplicate->setting_id)
                ->where('user_id', $duplicate->user_id)
                ->where('type', $duplicate->type)
                ->where('id', '<>', (int) $duplicate->keep_id)
                ->delete();
        }
    }

    private static function ensureIndexes(): void
    {
        if (! Schema::hasIndex(self::TABLE, self::UNIQUE_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(['setting_id', 'user_id', 'type'], self::UNIQUE_INDEX);
            });
        }

        if (! Schema::hasIndex(self::TABLE, self::TYPE_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['setting_id', 'type'], self::TYPE_INDEX);
            });
        }
    }

    private static function ensureForeignKeys(): void
    {
        if (! self::canAlterForeignKeysInPlace()) {
            return;
        }

        if (! self::hasForeignKeyOnColumn('setting_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreign('setting_id', self::SETTING_FOREIGN)
                    ->references('id')
                    ->on('company_document_expiry_notification_settings')
                    ->cascadeOnDelete();
            });
        }

        if (! self::hasForeignKeyOnColumn('user_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreign('user_id', self::USER_FOREIGN)
                    ->references('id')
                    ->on('users')
                    ->cascadeOnDelete();
            });
        }
    }

    private static function assertSchemaIntegrity(): void
    {
        $problems = [];

        foreach (['id', 'setting_id', 'user_id', 'type', 'created_at', 'updated_at'] as $column) {
            if (! Schema::hasColumn(self::TABLE, $column)) {
                $problems[] = "missing column [{$column}]";
            }
        }

        if (! Schema::hasIndex(self::TABLE, self::UNIQUE_INDEX)) {
            $problems[] = 'missing recipient uniqueness constraint';
        }

        if (! Schema::hasIndex(self::TABLE, self::TYPE_INDEX)) {
            $problems[] = 'missing setting/type lookup index';
        }

        if (self::canAlterForeignKeysInPlace()) {
            if (! self::hasForeignKeyOnColumn('setting_id')) {
                $problems[] = 'missing setting_id foreign key';
            }

            if (! self::hasForeignKeyOnColumn('user_id')) {
                $problems[] = 'missing user_id foreign key';
            }
        }

        if ($problems !== []) {
            throw new RuntimeException(sprintf(
                'Recipient schema repair for [%s] is incomplete: %s.',
                self::TABLE,
                implode('; ', $problems),
            ));
        }
    }

    private static function canAlterForeignKeysInPlace(): bool
    {
        return DB::getDriverName() !== 'sqlite';
    }

    private static function hasForeignKeyOnColumn(string $column): bool
    {
        foreach (Schema::getForeignKeys(self::TABLE) as $foreignKey) {
            if (in_array($column, $foreignKey['columns'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }
}
