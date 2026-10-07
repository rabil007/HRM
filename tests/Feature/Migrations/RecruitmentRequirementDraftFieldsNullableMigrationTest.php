<?php

use App\Enums\Recruitment\RequirementStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\RecruitmentRequirement;
use Illuminate\Support\Facades\Schema;

function recruitmentDraftMigrationFixtures(): array
{
    $country = Country::query()->create([
        'code' => 'RDM',
        'name' => 'Recruitment Draft Migration Country',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'RDM',
        'name' => 'Recruitment Draft Migration Currency',
        'symbol' => 'R$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Recruitment Draft Migration Co',
        'slug' => 'recruitment-draft-migration-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    return compact('company');
}

function recruitmentDraftFieldsNullableMigration()
{
    return require database_path('migrations/2026_10_07_142153_make_recruitment_requirement_draft_fields_nullable.php');
}

test('rolling back nullable draft fields aborts when partial draft rows exist', function () {
    ['company' => $company] = recruitmentDraftMigrationFixtures();

    RecruitmentRequirement::query()->create([
        'company_id' => $company->id,
        'requirement_number' => 'REQ-2026-900100',
        'client_id' => null,
        'request_received_date' => null,
        'required_by_date' => null,
        'priority' => 'normal',
        'status' => RequirementStatus::Draft,
        'notes' => 'Partial draft row',
    ]);

    $migration = recruitmentDraftFieldsNullableMigration();

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Cannot roll back nullable recruitment requirement draft fields while partial Draft rows exist');

    expect(Schema::hasColumn('recruitment_requirements', 'client_id'))->toBeTrue();
});

test('rolling back nullable draft fields succeeds when no partial rows exist', function () {
    ['company' => $company] = recruitmentDraftMigrationFixtures();

    $client = Client::query()->create([
        'name' => 'Complete Draft Client',
        'is_active' => true,
    ]);

    RecruitmentRequirement::query()->create([
        'company_id' => $company->id,
        'requirement_number' => 'REQ-2026-900101',
        'client_id' => $client->id,
        'request_received_date' => now()->subDay(),
        'required_by_date' => now()->addDays(7),
        'priority' => 'normal',
        'status' => RequirementStatus::Draft,
    ]);

    $migration = recruitmentDraftFieldsNullableMigration();

    $migration->down();
    $migration->up();

    expect(Schema::hasColumn('recruitment_requirements', 'client_id'))->toBeTrue();
});
