<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nullable generated live-login key.
     *
     * Conceptually UNIQUE(CASE WHEN deleted_at IS NULL THEN LOWER(email) ELSE NULL END).
     * Soft-deleted rows store NULL, so they do not occupy the live identity.
     * Multiple NULLs are allowed by MySQL, MariaDB, and SQLite unique indexes.
     *
     * Virtual (`AS (expr)`), not STORED: SQLite cannot ADD a STORED generated
     * column via ALTER TABLE, and Pest uses sqlite :memory:. MySQL/MariaDB treat
     * unadorned AS (expr) as VIRTUAL and still enforce a unique secondary index.
     */
    public const COLUMN = 'active_login_email';

    public const INDEX = 'uq_users_active_login_email';

    public const EXPRESSION = 'CASE WHEN deleted_at IS NULL THEN LOWER(email) ELSE NULL END';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->abortIfDuplicateLiveEmailsExist();

        Schema::table('users', function (Blueprint $table): void {
            $table->string(self::COLUMN)
                ->nullable()
                ->virtualAs(self::EXPRESSION);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->unique(self::COLUMN, self::INDEX);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(self::INDEX);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(self::COLUMN);
        });
    }

    /**
     * Abort before any schema change when live duplicate identities exist.
     *
     * Uses the query builder only (no Eloquent). Does not print emails, merge
     * rows, delete rows, or rewrite stored email values.
     */
    private function abortIfDuplicateLiveEmailsExist(): void
    {
        $duplicateGroupCount = DB::table('users')
            ->whereNull('deleted_at')
            ->selectRaw('LOWER(email) as normalized_email')
            ->groupByRaw('LOWER(email)')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        if ($duplicateGroupCount === 0) {
            return;
        }

        throw new RuntimeException(
            "Cannot add unique live User email identity: {$duplicateGroupCount} duplicate non-deleted LOWER(email) group(s) already exist. Resolve them first with: php artisan users:audit-duplicate-emails --show-emails"
        );
    }
};
