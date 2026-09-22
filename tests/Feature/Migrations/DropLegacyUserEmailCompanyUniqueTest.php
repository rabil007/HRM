<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @return Migration
 */
function dropLegacyUserEmailCompanyUniqueMigration(): object
{
    return require database_path('migrations/2026_09_22_125318_drop_legacy_user_email_company_unique_from_users_table.php');
}

function usersTableIndexNames()
{
    return collect(Schema::getIndexes('users'))->pluck('name');
}

test('migration drops legacy company email unique and keeps live login uniqueness', function () {
    expect(usersTableIndexNames()->contains('uq_users_active_login_email'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'active_login_email'))->toBeTrue()
        ->and(usersTableIndexNames()->contains('uq_user_email_company'))->toBeFalse()
        ->and(usersTableIndexNames()->contains('idx_users_company_email'))->toBeTrue();

    $migration = dropLegacyUserEmailCompanyUniqueMigration();
    $migration->up();

    expect(usersTableIndexNames()->contains('uq_user_email_company'))->toBeFalse()
        ->and(usersTableIndexNames()->contains('idx_users_company_email'))->toBeTrue()
        ->and(usersTableIndexNames()->contains('uq_users_active_login_email'))->toBeTrue();
});

test('migration is idempotent when legacy unique is already gone', function () {
    $migration = dropLegacyUserEmailCompanyUniqueMigration();
    $migration->up();
    $migration->up();

    expect(usersTableIndexNames()->contains('uq_user_email_company'))->toBeFalse()
        ->and(usersTableIndexNames()->contains('idx_users_company_email'))->toBeTrue()
        ->and(usersTableIndexNames()->contains('uq_users_active_login_email'))->toBeTrue();
});

test('migration aborts when live login uniqueness mechanism is missing', function () {
    $migration = dropLegacyUserEmailCompanyUniqueMigration();

    Artisan::call('migrate:rollback', [
        '--path' => 'database/migrations/2026_09_22_125318_drop_legacy_user_email_company_unique_from_users_table.php',
        '--force' => true,
    ]);

    expect(usersTableIndexNames()->contains('uq_user_email_company'))->toBeTrue();

    Schema::table('users', function ($table): void {
        $table->dropUnique('uq_users_active_login_email');
    });

    try {
        $migration->up();
        $this->fail('Expected migration to abort when live uniqueness index is missing.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())
            ->toContain('uq_users_active_login_email')
            ->toContain('active_login_email uniqueness migration');
    }

    expect(usersTableIndexNames()->contains('uq_user_email_company'))->toBeTrue();

    Schema::table('users', function ($table): void {
        $table->unique('active_login_email', 'uq_users_active_login_email');
    });

    Artisan::call('migrate', [
        '--path' => 'database/migrations/2026_09_22_125318_drop_legacy_user_email_company_unique_from_users_table.php',
        '--force' => true,
    ]);
});

test('rollback recreates legacy unique when no company email duplicates exist', function () {
    $user = User::factory()->create(['email' => 'rollback-safe@example.com']);
    $snapshot = (array) DB::table('users')->where('id', $user->id)->first();
    unset($snapshot['active_login_email']);

    Artisan::call('migrate:rollback', [
        '--path' => 'database/migrations/2026_09_22_125318_drop_legacy_user_email_company_unique_from_users_table.php',
        '--force' => true,
    ]);

    $after = (array) DB::table('users')->where('id', $user->id)->first();
    unset($after['active_login_email']);

    expect(usersTableIndexNames()->contains('uq_user_email_company'))->toBeTrue()
        ->and(usersTableIndexNames()->contains('idx_users_company_email'))->toBeFalse()
        ->and(usersTableIndexNames()->contains('uq_users_active_login_email'))->toBeTrue()
        ->and($after)->toBe($snapshot);

    Artisan::call('migrate', [
        '--path' => 'database/migrations/2026_09_22_125318_drop_legacy_user_email_company_unique_from_users_table.php',
        '--force' => true,
    ]);
});

test('rollback aborts without rewriting rows when same-company soft-deleted email reuse exists', function () {
    ['companyA' => $companyA] = makeTwoCompaniesForUserEmailIdentity('legacy-down');

    $deleted = User::factory()->create([
        'email' => 'reuse-down@example.com',
        'company_id' => $companyA->id,
    ]);
    $deleted->delete();

    $live = User::factory()->create([
        'email' => 'reuse-down@example.com',
        'company_id' => $companyA->id,
    ]);

    $ids = [$deleted->id, $live->id];
    $before = DB::table('users')->whereIn('id', $ids)->orderBy('id')->get();

    $migration = dropLegacyUserEmailCompanyUniqueMigration();

    try {
        $migration->down();
        $this->fail('Expected rollback to abort when duplicate company/email pairs exist.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())
            ->toContain('uq_user_email_company')
            ->toContain('duplicate (company_id, email)')
            ->not->toContain('reuse-down@example.com');
    }

    $after = DB::table('users')->whereIn('id', $ids)->orderBy('id')->get();

    expect(usersTableIndexNames()->contains('uq_user_email_company'))->toBeFalse()
        ->and(usersTableIndexNames()->contains('idx_users_company_email'))->toBeTrue()
        ->and($after->map(fn ($row): array => (array) $row)->all())
        ->toBe($before->map(fn ($row): array => (array) $row)->all());
});

test('same home company soft-deleted email reuse is allowed after migration', function () {
    ['companyA' => $companyA] = makeTwoCompaniesForUserEmailIdentity('same-home');

    $deleted = User::factory()->create([
        'email' => 'same-home-ok@example.com',
        'company_id' => $companyA->id,
    ]);
    $deleted->delete();

    $live = User::factory()->create([
        'email' => 'same-home-ok@example.com',
        'company_id' => $companyA->id,
    ]);

    expect($live->id)->not->toBe($deleted->id)
        ->and(User::withTrashed()->where('email', 'same-home-ok@example.com')->count())->toBe(2)
        ->and($deleted->fresh()?->trashed())->toBeTrue();
});

test('two live users with the same normalized email are still rejected', function () {
    ['companyA' => $companyA, 'companyB' => $companyB] = makeTwoCompaniesForUserEmailIdentity('live-dup');

    User::factory()->create([
        'email' => 'still-unique@example.com',
        'company_id' => $companyA->id,
    ]);

    expect(fn () => User::factory()->create([
        'email' => 'Still-Unique@Example.com',
        'company_id' => $companyB->id,
    ]))->toThrow(QueryException::class);

    expect(User::query()->whereRaw('LOWER(email) = ?', ['still-unique@example.com'])->count())->toBe(1);
});
