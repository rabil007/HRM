<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remove the legacy (company_id, email) unique index that blocked re-inviting
 * a soft-deleted User's email in the same home company.
 *
 * Live uniqueness remains enforced by active_login_email +
 * uq_users_active_login_email (NULL for soft-deleted rows).
 */
return new class extends Migration
{
    public const LEGACY_UNIQUE = 'uq_user_email_company';

    public const LOOKUP_INDEX = 'idx_users_company_email';

    public const LIVE_COLUMN = 'active_login_email';

    public const LIVE_UNIQUE = 'uq_users_active_login_email';

    /**
     * Run the migrations.
     *
     * MySQL may use uq_user_email_company as the supporting index for the
     * users.company_id foreign key. Add the non-unique replacement first so
     * the FK keeps an eligible index, then drop the legacy unique.
     */
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $this->assertLiveLoginUniquenessMechanismExists();

        if (! $this->hasIndexNamed('users', self::LOOKUP_INDEX)) {
            Schema::table('users', function (Blueprint $table): void {
                $table->index(['company_id', 'email'], self::LOOKUP_INDEX);
            });
        }

        if ($this->hasIndexNamed('users', self::LEGACY_UNIQUE)) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropUnique(self::LEGACY_UNIQUE);
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * Aborts rather than rewriting User rows when (company_id, email)
     * duplicates now exist (including soft-deleted historical rows).
     *
     * Recreate the unique index before dropping the lookup index so the
     * company_id foreign key never loses its supporting index on MySQL.
     */
    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $this->abortIfLegacyCompanyEmailDuplicatesExist();

        if (! $this->hasIndexNamed('users', self::LEGACY_UNIQUE)) {
            Schema::table('users', function (Blueprint $table): void {
                $table->unique(['company_id', 'email'], self::LEGACY_UNIQUE);
            });
        }

        if ($this->hasIndexNamed('users', self::LOOKUP_INDEX)) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropIndex(self::LOOKUP_INDEX);
            });
        }
    }

    private function assertLiveLoginUniquenessMechanismExists(): void
    {
        if (! Schema::hasColumn('users', self::LIVE_COLUMN)) {
            throw new RuntimeException(
                'Cannot drop '.self::LEGACY_UNIQUE.': required column users.'.self::LIVE_COLUMN.' is missing. Apply the active_login_email uniqueness migration first.'
            );
        }

        if (! $this->hasIndexNamed('users', self::LIVE_UNIQUE)) {
            throw new RuntimeException(
                'Cannot drop '.self::LEGACY_UNIQUE.': required unique index '.self::LIVE_UNIQUE.' is missing. Apply the active_login_email uniqueness migration first.'
            );
        }
    }

    private function abortIfLegacyCompanyEmailDuplicatesExist(): void
    {
        $duplicateGroupCount = DB::table('users')
            ->selectRaw('company_id, email')
            ->groupBy('company_id', 'email')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        if ($duplicateGroupCount === 0) {
            return;
        }

        throw new RuntimeException(
            'Cannot recreate unique index '.self::LEGACY_UNIQUE.": {$duplicateGroupCount} duplicate (company_id, email) group(s) exist (including soft-deleted rows). Resolve duplicates before rolling back; this migration will not delete or rewrite User records."
        );
    }

    private function hasIndexNamed(string $table, string $indexName): bool
    {
        if (method_exists(Schema::class, 'hasIndex') && Schema::hasIndex($table, $indexName)) {
            return true;
        }

        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? null) === $indexName) {
                return true;
            }
        }

        return false;
    }
};
