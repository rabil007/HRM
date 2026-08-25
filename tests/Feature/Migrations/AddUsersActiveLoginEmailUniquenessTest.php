<?php

use App\Models\User;
use App\Rules\UniqueUserEmail;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * @return Migration
 */
function usersActiveLoginEmailUniquenessMigration(): object
{
    return require database_path('migrations/2026_08_25_120530_add_active_login_email_uniqueness_to_users_table.php');
}

/**
 * Insert a users row without Eloquent mutators or UniqueUserEmail validation.
 *
 * @param  array<string, mixed>  $attributes
 */
function insertUserRowBypassingIdentityRules(array $attributes): int
{
    return (int) DB::table('users')->insertGetId([
        'name' => $attributes['name'] ?? 'Direct User',
        'email' => $attributes['email'],
        'password' => $attributes['password'] ?? 'hash',
        'status' => $attributes['status'] ?? 'active',
        'company_id' => $attributes['company_id'] ?? null,
        'email_verified_at' => $attributes['email_verified_at'] ?? now(),
        'remember_token' => $attributes['remember_token'] ?? 'token',
        'created_at' => now(),
        'updated_at' => now(),
        'deleted_at' => $attributes['deleted_at'] ?? null,
    ]);
}

function usersIndexNames()
{
    return collect(Schema::getIndexes('users'))->pluck('name');
}

test('migration succeeds on clean User data', function () {
    User::factory()->create(['email' => 'clean-one@example.com']);
    User::factory()->create(['email' => 'clean-two@example.com']);

    expect(Schema::hasColumn('users', 'active_login_email'))->toBeTrue()
        ->and(usersIndexNames()->contains('uq_users_active_login_email'))->toBeTrue()
        ->and(usersIndexNames()->contains('uq_user_email_company'))->toBeTrue();

    $migration = usersActiveLoginEmailUniquenessMigration();
    $migration->down();
    $migration->up();

    expect(Schema::hasColumn('users', 'active_login_email'))->toBeTrue()
        ->and(usersIndexNames()->contains('uq_users_active_login_email'))->toBeTrue()
        ->and(User::query()->where('email', 'clean-one@example.com')->exists())->toBeTrue()
        ->and(User::query()->where('email', 'clean-two@example.com')->exists())->toBeTrue();
});

test('one live normalized email is allowed', function () {
    $user = User::factory()->create(['email' => 'Live.Owner@Example.com']);

    expect($user->email)->toBe('live.owner@example.com')
        ->and(DB::table('users')->where('id', $user->id)->value('active_login_email'))
        ->toBe('live.owner@example.com')
        ->and($user->toArray())->not->toHaveKey('active_login_email');
});

test('second live same email in another company is rejected by the database', function () {
    ['companyA' => $companyA, 'companyB' => $companyB] = makeTwoCompaniesForUserEmailIdentity('db-dup');

    User::factory()->create([
        'email' => 'taken@example.com',
        'company_id' => $companyA->id,
    ]);

    expect(fn () => User::factory()->create([
        'email' => 'taken@example.com',
        'company_id' => $companyB->id,
    ]))->toThrow(QueryException::class);

    expect(User::query()->whereRaw('LOWER(email) = ?', ['taken@example.com'])->count())->toBe(1);
});

test('mixed-case duplicate is rejected at the database level', function () {
    ['companyA' => $companyA, 'companyB' => $companyB] = makeTwoCompaniesForUserEmailIdentity('mixed');

    insertUserRowBypassingIdentityRules([
        'email' => 'JOHN@example.com',
        'company_id' => $companyA->id,
    ]);

    expect(fn () => insertUserRowBypassingIdentityRules([
        'email' => 'john@example.com',
        'company_id' => $companyB->id,
    ]))->toThrow(QueryException::class);

    expect(DB::table('users')->whereRaw('LOWER(email) = ?', ['john@example.com'])->whereNull('deleted_at')->count())
        ->toBe(1)
        ->and(DB::table('users')->where('email', 'JOHN@example.com')->exists())->toBeTrue();
});

test('application UniqueUserEmail still rejects another live users email', function () {
    User::factory()->create(['email' => 'validator-taken@example.com']);

    $validator = Validator::make(
        ['email' => 'Validator-Taken@Example.com'],
        ['email' => [new UniqueUserEmail]],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('email'))->toBeTrue();
});

test('soft-deleted User does not occupy the global live email identity', function () {
    ['companyA' => $companyA, 'companyB' => $companyB] = makeTwoCompaniesForUserEmailIdentity('soft');

    $deleted = User::factory()->create([
        'email' => 'reusable-db@example.com',
        'company_id' => $companyA->id,
    ]);
    $deleted->delete();

    expect(DB::table('users')->where('id', $deleted->id)->value('active_login_email'))->toBeNull();

    $live = User::factory()->create([
        'email' => 'reusable-db@example.com',
        'company_id' => $companyB->id,
    ]);

    expect($live->id)->not->toBe($deleted->id)
        ->and(DB::table('users')->where('id', $live->id)->value('active_login_email'))
        ->toBe('reusable-db@example.com')
        ->and(User::withTrashed()->whereRaw('LOWER(email) = ?', ['reusable-db@example.com'])->count())
        ->toBe(2);
});

test('restoring a deleted User fails when another live User owns that email', function () {
    ['companyA' => $companyA, 'companyB' => $companyB] = makeTwoCompaniesForUserEmailIdentity('restore');

    $deleted = User::factory()->create([
        'email' => 'restore-conflict@example.com',
        'company_id' => $companyA->id,
    ]);
    $deleted->delete();

    $live = User::factory()->create([
        'email' => 'restore-conflict@example.com',
        'company_id' => $companyB->id,
    ]);

    expect(fn () => $deleted->restore())->toThrow(QueryException::class);

    expect($deleted->fresh()?->deleted_at)->not->toBeNull()
        ->and(User::query()->find($live->id))->not->toBeNull()
        ->and(User::withTrashed()->find($deleted->id)?->trashed())->toBeTrue();
});

test('legacy company email unique still blocks same-home-company reuse after soft delete', function () {
    ['companyA' => $companyA] = makeTwoCompaniesForUserEmailIdentity('legacy');

    $deleted = User::factory()->create([
        'email' => 'same-home@example.com',
        'company_id' => $companyA->id,
    ]);
    $deleted->delete();

    expect(fn () => User::factory()->create([
        'email' => 'same-home@example.com',
        'company_id' => $companyA->id,
    ]))->toThrow(QueryException::class);

    expect(User::withTrashed()->where('email', 'same-home@example.com')->count())->toBe(1);
});

test('migration preflight fails safely when duplicate live normalized emails already exist', function () {
    $migration = usersActiveLoginEmailUniquenessMigration();
    $migration->down();

    ['companyA' => $companyA, 'companyB' => $companyB] = makeTwoCompaniesForUserEmailIdentity('preflight');
    $email = 'dup-preflight@example.com';

    $firstId = insertUserRowBypassingIdentityRules([
        'email' => $email,
        'company_id' => $companyA->id,
        'name' => 'Preflight A',
    ]);
    $secondId = insertUserRowBypassingIdentityRules([
        'email' => 'DUP-PREFLIGHT@example.com',
        'company_id' => $companyB->id,
        'name' => 'Preflight B',
    ]);

    $rowsBefore = DB::table('users')->whereIn('id', [$firstId, $secondId])->orderBy('id')->get();

    try {
        $migration->up();
        $this->fail('Expected the migration to abort when live duplicate emails exist.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())
            ->toContain('Cannot add unique live User email identity')
            ->toContain('1 duplicate non-deleted LOWER(email) group')
            ->toContain('php artisan users:audit-duplicate-emails --show-emails')
            ->not->toContain($email)
            ->not->toContain('DUP-PREFLIGHT@example.com');
    }

    $rowsAfter = DB::table('users')->whereIn('id', [$firstId, $secondId])->orderBy('id')->get();

    expect(Schema::hasColumn('users', 'active_login_email'))->toBeFalse()
        ->and(usersIndexNames()->contains('uq_users_active_login_email'))->toBeFalse()
        ->and($rowsAfter->map(fn ($row): array => (array) $row)->all())
        ->toBe($rowsBefore->map(fn ($row): array => (array) $row)->all());
});

test('rollback removes the new uniqueness mechanism without changing User rows', function () {
    $user = User::factory()->create(['email' => 'rollback-keep@example.com']);
    $snapshot = (array) DB::table('users')->where('id', $user->id)->first();
    unset($snapshot['active_login_email']);

    expect(Schema::hasColumn('users', 'active_login_email'))->toBeTrue();

    Artisan::call('migrate:rollback', [
        '--path' => 'database/migrations/2026_08_25_120530_add_active_login_email_uniqueness_to_users_table.php',
        '--force' => true,
    ]);

    $after = (array) DB::table('users')->where('id', $user->id)->first();

    expect(Schema::hasColumn('users', 'active_login_email'))->toBeFalse()
        ->and(usersIndexNames()->contains('uq_users_active_login_email'))->toBeFalse()
        ->and(usersIndexNames()->contains('uq_user_email_company'))->toBeTrue()
        ->and($after)->toBe($snapshot);

    Artisan::call('migrate', [
        '--path' => 'database/migrations/2026_08_25_120530_add_active_login_email_uniqueness_to_users_table.php',
        '--force' => true,
    ]);

    expect(Schema::hasColumn('users', 'active_login_email'))->toBeTrue()
        ->and(DB::table('users')->where('id', $user->id)->value('email'))->toBe('rollback-keep@example.com');
});
