<?php

namespace App\Support\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

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

        if (! self::hasRequiredColumns()) {
            if (self::hasRows()) {
                return;
            }

            Schema::dropIfExists(self::TABLE);
            self::createTable();

            return;
        }

        self::ensureIndexes();
        self::ensureForeignKeys();
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

    private static function hasRequiredColumns(): bool
    {
        return Schema::hasColumns(self::TABLE, ['id', 'setting_id', 'user_id', 'type']);
    }

    private static function ensureIndexes(): void
    {
        if (! Schema::hasIndex(self::TABLE, self::UNIQUE_INDEX)) {
            try {
                Schema::table(self::TABLE, function (Blueprint $table): void {
                    $table->unique(['setting_id', 'user_id', 'type'], self::UNIQUE_INDEX);
                });
            } catch (Throwable) {
                // Leave existing rows intact if uniqueness cannot be added.
            }
        }

        if (! Schema::hasIndex(self::TABLE, self::TYPE_INDEX)) {
            try {
                Schema::table(self::TABLE, function (Blueprint $table): void {
                    $table->index(['setting_id', 'type'], self::TYPE_INDEX);
                });
            } catch (Throwable) {
                // Leave existing rows intact if the supporting index cannot be added.
            }
        }
    }

    private static function ensureForeignKeys(): void
    {
        if (! self::hasForeignKey(self::SETTING_FOREIGN)) {
            try {
                Schema::table(self::TABLE, function (Blueprint $table): void {
                    $table->foreign('setting_id', self::SETTING_FOREIGN)
                        ->references('id')
                        ->on('company_document_expiry_notification_settings')
                        ->cascadeOnDelete();
                });
            } catch (Throwable) {
                // SQLite and already-constrained MySQL tables may not accept an extra FK.
            }
        }

        if (! self::hasForeignKey(self::USER_FOREIGN) && ! self::hasForeignKeyOnColumn('user_id')) {
            try {
                Schema::table(self::TABLE, function (Blueprint $table): void {
                    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                });
            } catch (Throwable) {
                // Leave existing rows intact if the FK cannot be added in place.
            }
        }
    }

    private static function hasForeignKey(string $name): bool
    {
        foreach (Schema::getForeignKeys(self::TABLE) as $foreignKey) {
            if (($foreignKey['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
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
