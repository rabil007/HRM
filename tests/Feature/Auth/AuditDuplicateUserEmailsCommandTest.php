<?php

use App\Models\Employee;
use App\Models\User;
use App\Support\Auth\UserEmailIdentity;

test('duplicate audit command returns success when identities are unique', function () {
    User::factory()->create(['email' => 'one@example.com']);
    User::factory()->create(['email' => 'two@example.com']);

    $this->artisan('users:audit-duplicate-emails')
        ->expectsOutput('No duplicate User email identities found.')
        ->assertSuccessful();
});

test('duplicate audit command returns non-zero and reports safe diagnostics', function () {
    $fixtures = createDuplicateEmailUsers('audit.user@example.com');
    $employee = Employee::factory()->forCompany($fixtures['companyA'])->create([
        'user_id' => $fixtures['userA']->id,
        'status' => 'active',
    ]);

    $fixtures['userA']->companies()->syncWithoutDetaching([
        $fixtures['companyA']->id => ['status' => 'active'],
        $fixtures['companyB']->id => ['status' => 'inactive'],
    ]);

    $groups = app(UserEmailIdentity::class)->duplicateGroups();

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['identity_count'])->toBe(2)
        ->and(collect($groups[0]['users'])->pluck('id')->sort()->values()->all())
        ->toBe(collect([$fixtures['userA']->id, $fixtures['userB']->id])->sort()->values()->all())
        ->and($groups[0]['users'][0])->not->toHaveKey('password')
        ->and($groups[0]['users'][0])->not->toHaveKey('remember_token')
        ->and($groups[0]['users'][0])->not->toHaveKey('two_factor_secret')
        ->and($groups[0]['users'][0])->toHaveKeys(['id', 'status', 'home_company_id', 'membership_count', 'employee_link_count', 'role_assignment_count']);

    $this->artisan('users:audit-duplicate-emails')
        ->assertFailed()
        ->expectsOutputToContain('a***@example.com')
        ->doesntExpectOutputToContain('audit.user@example.com');

    expect($employee->fresh()->user_id)->toBe($fixtures['userA']->id);
});

test('duplicate audit command can print emails when explicitly requested', function () {
    createDuplicateEmailUsers('visible@example.com');

    $this->artisan('users:audit-duplicate-emails', ['--show-emails' => true])
        ->assertFailed()
        ->expectsOutputToContain('visible@example.com');
});

test('duplicate audit command ignores a soft-deleted extra identity', function () {
    $fixtures = createDuplicateEmailUsers('soft-audit@example.com');
    $fixtures['userB']->delete();

    $this->artisan('users:audit-duplicate-emails')
        ->expectsOutput('No duplicate User email identities found.')
        ->assertSuccessful();
});
