<?php

use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Jobs\DeliverRequirementLifecycleEmailJob;
use App\Mail\RequirementSubmittedForApprovalMail;
use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Position;
use App\Models\Project;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementLine;
use App\Models\User;
use App\Support\Recruitment\RequirementPresenter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function createSalaryTestCompany(string $name, string $code): Company
{
    $country = Country::query()->firstOrCreate(
        ['code' => $code],
        [
            'name' => "{$name} Country",
            'dial_code' => '+999',
            'is_active' => true,
        ]
    );

    $currency = Currency::query()->firstOrCreate(
        ['code' => $code],
        [
            'name' => "{$name} Currency",
            'symbol' => '$',
            'is_active' => true,
        ]
    );

    return Company::query()->create([
        'name' => $name,
        'slug' => strtolower(str_replace(' ', '-', $name)).'-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
}

function createSalaryTestUser(Company $company, array $permissions = [], array $attributes = []): User
{
    $user = User::factory()->create(array_merge([
        'company_id' => $company->id,
        'status' => 'active',
    ], $attributes));

    DB::table('company_user')->updateOrInsert(
        ['company_id' => $company->id, 'user_id' => $user->id],
        ['status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    );

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

    foreach ($permissions as $permName) {
        $permission = Permission::query()->firstOrCreate([
            'name' => $permName,
            'guard_name' => 'web',
        ]);
        $user->givePermissionTo($permission);
    }

    return $user;
}

beforeEach(function () {
    $this->companyA = createSalaryTestCompany('Alpha Shipping', 'AED');
    $this->companyB = createSalaryTestCompany('Beta Logistics', 'USD');

    $permissions = [
        'recruitment.requirements.view',
        'recruitment.requirements.create',
        'recruitment.requirements.update',
        'recruitment.requirements.submit',
        'recruitment.requirements.approve',
        'recruitment.requirements.close',
        'recruitment.requirements.cancel',
        'recruitment.requirements.reopen',
        'recruitment.requirements.attachments.download',
    ];

    $this->requesterA = createSalaryTestUser($this->companyA, $permissions, ['name' => 'Requester A', 'email' => 'requester.a@example.com']);
    $this->recruiterA = createSalaryTestUser($this->companyA, $permissions, ['name' => 'Recruiter A', 'email' => 'recruiter.a@example.com']);

    $this->requesterB = createSalaryTestUser($this->companyB, $permissions, ['name' => 'Requester B', 'email' => 'requester.b@example.com']);
    $this->recruiterB = createSalaryTestUser($this->companyB, $permissions, ['name' => 'Recruiter B', 'email' => 'recruiter.b@example.com']);

    $this->clientA = Client::query()->create(['name' => 'Client Alpha', 'is_active' => true]);
    $this->projectA = Project::query()->create(['title' => 'Project Alpha', 'is_active' => true]);
    $this->projectA->clients()->sync([$this->clientA->id]);

    $this->positionA = Position::query()->create([
        'company_id' => $this->companyA->id,
        'title' => 'Chief Engineer',
        'min_salary' => 12000.00,
        'max_salary' => 16000.00,
        'status' => 'active',
    ]);

    $this->positionNoSalary = Position::query()->create([
        'company_id' => $this->companyA->id,
        'title' => 'Deck Cadet',
        'min_salary' => null,
        'max_salary' => null,
        'status' => 'active',
    ]);

    $this->positionB = Position::query()->create([
        'company_id' => $this->companyB->id,
        'title' => 'Beta Captain',
        'min_salary' => 20000.00,
        'max_salary' => 25000.00,
        'status' => 'active',
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->companyA->id);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

test('1. position options payload includes min_salary, max_salary and active company currency', function () {
    $this->actingAs($this->requesterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get('/organization/recruitment/requirements')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/recruitment/requirements/index')
            ->has('options.positions', fn (Assert $positions) => $positions
                ->where('0.id', $this->positionA->id)
                ->where('0.title', 'Chief Engineer')
                ->where('0.min_salary', fn ($val) => (float) $val === 12000.0)
                ->where('0.max_salary', fn ($val) => (float) $val === 16000.0)
                ->etc()
            )
            ->where('options.currency_code', 'AED')
        );
});

test('2. creator can override default position salary range', function () {
    $payload = [
        'client_id' => $this->clientA->id,
        'project_id' => $this->projectA->id,
        'request_received_date' => now()->subDay()->toDateString(),
        'required_by_date' => now()->addDays(14)->toDateString(),
        'priority' => 'normal',
        'assigned_to' => $this->recruiterA->id,
        'submit_for_approval' => true,
        'ignore_duplicate_warning' => true,
        'positions' => [
            [
                'position_id' => $this->positionA->id,
                'required_headcount' => 1,
                'salary_min' => '13500.00',
                'salary_max' => '17500.00',
            ],
        ],
    ];

    $response = $this->actingAs($this->requesterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post('/organization/recruitment/requirements', $payload);

    $response->assertRedirect();

    $line = RecruitmentRequirementLine::query()->latest('id')->first();
    expect($line)->not->toBeNull()
        ->and((float) $line->salary_min)->toBe(13500.00)
        ->and((float) $line->salary_max)->toBe(17500.00)
        ->and($line->salary_currency_code)->toBe('AED');
});

test('3. positions without salary support manual entry', function () {
    $payload = [
        'client_id' => $this->clientA->id,
        'project_id' => $this->projectA->id,
        'request_received_date' => now()->subDay()->toDateString(),
        'required_by_date' => now()->addDays(14)->toDateString(),
        'priority' => 'normal',
        'assigned_to' => $this->recruiterA->id,
        'submit_for_approval' => true,
        'ignore_duplicate_warning' => true,
        'positions' => [
            [
                'position_id' => $this->positionNoSalary->id,
                'required_headcount' => 1,
                'salary_min' => '4500.00',
                'salary_max' => '5500.00',
            ],
        ],
    ];

    $this->actingAs($this->requesterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post('/organization/recruitment/requirements', $payload)
        ->assertRedirect();

    $line = RecruitmentRequirementLine::query()->latest('id')->first();
    expect($line)->not->toBeNull()
        ->and((float) $line->salary_min)->toBe(4500.00)
        ->and((float) $line->salary_max)->toBe(5500.00)
        ->and($line->salary_currency_code)->toBe('AED');
});

test('4. a draft can be saved with missing or null salary', function () {
    $payload = [
        'client_id' => $this->clientA->id,
        'project_id' => $this->projectA->id,
        'request_received_date' => now()->subDay()->toDateString(),
        'required_by_date' => now()->addDays(14)->toDateString(),
        'priority' => 'normal',
        'assigned_to' => $this->recruiterA->id,
        'submit_for_approval' => false,
        'ignore_duplicate_warning' => true,
        'positions' => [
            [
                'position_id' => $this->positionA->id,
                'required_headcount' => 1,
                'salary_min' => null,
                'salary_max' => null,
            ],
        ],
    ];

    $this->actingAs($this->requesterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post('/organization/recruitment/requirements', $payload)
        ->assertRedirect();

    $requirement = RecruitmentRequirement::query()->latest('id')->first();
    expect($requirement->status)->toBe(RequirementStatus::Draft);

    $line = $requirement->lines()->first();
    expect($line->salary_min)->toBeNull()
        ->and($line->salary_max)->toBeNull();
});

test('5. submission fails when either salary value is missing', function () {
    $payload = [
        'client_id' => $this->clientA->id,
        'project_id' => $this->projectA->id,
        'request_received_date' => now()->subDay()->toDateString(),
        'required_by_date' => now()->addDays(14)->toDateString(),
        'priority' => 'normal',
        'assigned_to' => $this->recruiterA->id,
        'submit_for_approval' => true,
        'ignore_duplicate_warning' => true,
        'positions' => [
            [
                'position_id' => $this->positionA->id,
                'required_headcount' => 1,
                'salary_min' => '5000.00',
                'salary_max' => null,
            ],
        ],
    ];

    $this->actingAs($this->requesterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post('/organization/recruitment/requirements', $payload)
        ->assertSessionHasErrors(['positions.0.salary_max']);

    // Also test submitting an existing draft that lacks salary
    $req = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-TEST-005',
        'client_id' => $this->clientA->id,
        'project_id' => $this->projectA->id,
        'request_received_date' => now()->subDay(),
        'required_by_date' => now()->addDays(14),
        'priority' => 'normal',
        'status' => RequirementStatus::Draft,
        'assigned_to' => $this->recruiterA->id,
        'created_by' => $this->requesterA->id,
        'updated_by' => $this->requesterA->id,
    ]);

    RecruitmentRequirementLine::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $req->id,
        'position_id' => $this->positionA->id,
        'required_headcount' => 1,
        'salary_min' => null,
        'salary_max' => null,
        'status' => RequirementLineStatus::Open,
    ]);

    $this->actingAs($this->requesterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertSessionHasErrors(['salary']);
});

test('6. submission rejects negative or non-numeric values', function () {
    $payload = [
        'client_id' => $this->clientA->id,
        'project_id' => $this->projectA->id,
        'request_received_date' => now()->subDay()->toDateString(),
        'required_by_date' => now()->addDays(14)->toDateString(),
        'priority' => 'normal',
        'assigned_to' => $this->recruiterA->id,
        'submit_for_approval' => true,
        'ignore_duplicate_warning' => true,
        'positions' => [
            [
                'position_id' => $this->positionA->id,
                'required_headcount' => 1,
                'salary_min' => '-500.00',
                'salary_max' => '5000.00',
            ],
        ],
    ];

    $this->actingAs($this->requesterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post('/organization/recruitment/requirements', $payload)
        ->assertSessionHasErrors(['positions.0.salary_min']);
});

test('7. submission rejects salary_max < salary_min', function () {
    $payload = [
        'client_id' => $this->clientA->id,
        'project_id' => $this->projectA->id,
        'request_received_date' => now()->subDay()->toDateString(),
        'required_by_date' => now()->addDays(14)->toDateString(),
        'priority' => 'normal',
        'assigned_to' => $this->recruiterA->id,
        'submit_for_approval' => true,
        'ignore_duplicate_warning' => true,
        'positions' => [
            [
                'position_id' => $this->positionA->id,
                'required_headcount' => 1,
                'salary_min' => '8000.00',
                'salary_max' => '6000.00',
            ],
        ],
    ];

    $this->actingAs($this->requesterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post('/organization/recruitment/requirements', $payload)
        ->assertSessionHasErrors(['positions.0.salary_max']);
});

test('8. equal minimum and maximum values are accepted', function () {
    $payload = [
        'client_id' => $this->clientA->id,
        'project_id' => $this->projectA->id,
        'request_received_date' => now()->subDay()->toDateString(),
        'required_by_date' => now()->addDays(14)->toDateString(),
        'priority' => 'normal',
        'assigned_to' => $this->recruiterA->id,
        'submit_for_approval' => true,
        'ignore_duplicate_warning' => true,
        'positions' => [
            [
                'position_id' => $this->positionA->id,
                'required_headcount' => 1,
                'salary_min' => '7500.00',
                'salary_max' => '7500.00',
            ],
        ],
    ];

    $this->actingAs($this->requesterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post('/organization/recruitment/requirements', $payload)
        ->assertRedirect();

    $line = RecruitmentRequirementLine::query()->latest('id')->first();
    expect($line)->not->toBeNull()
        ->and((float) $line->salary_min)->toBe(7500.00)
        ->and((float) $line->salary_max)->toBe(7500.00);
});

test('9. currency is snapshotted from the active company', function () {
    $payload = [
        'client_id' => $this->clientA->id,
        'project_id' => $this->projectA->id,
        'request_received_date' => now()->subDay()->toDateString(),
        'required_by_date' => now()->addDays(14)->toDateString(),
        'priority' => 'normal',
        'assigned_to' => $this->recruiterA->id,
        'submit_for_approval' => true,
        'ignore_duplicate_warning' => true,
        'salary_currency_code' => 'EUR', // Attempt to spoof currency
        'positions' => [
            [
                'position_id' => $this->positionA->id,
                'required_headcount' => 1,
                'salary_min' => '5000.00',
                'salary_max' => '6000.00',
                'salary_currency_code' => 'EUR', // Attempt to spoof line currency
            ],
        ],
    ];

    $this->actingAs($this->requesterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post('/organization/recruitment/requirements', $payload)
        ->assertRedirect();

    $line = RecruitmentRequirementLine::query()->latest('id')->first();
    expect($line)->not->toBeNull()
        ->and($line->salary_currency_code)->toBe('AED');
});

test('10. changing the position after requirement creation does not change saved requirement salary snapshot', function () {
    $req = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-TEST-010',
        'client_id' => $this->clientA->id,
        'project_id' => $this->projectA->id,
        'request_received_date' => now()->subDay(),
        'required_by_date' => now()->addDays(14),
        'priority' => 'normal',
        'status' => RequirementStatus::PendingApproval,
        'assigned_to' => $this->recruiterA->id,
        'created_by' => $this->requesterA->id,
        'updated_by' => $this->requesterA->id,
    ]);

    $line = RecruitmentRequirementLine::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $req->id,
        'position_id' => $this->positionA->id,
        'required_headcount' => 1,
        'salary_min' => 12000.00,
        'salary_max' => 16000.00,
        'salary_currency_code' => 'AED',
        'status' => RequirementLineStatus::Open,
    ]);

    // Mutate position master data
    $this->positionA->update([
        'min_salary' => 20000.00,
        'max_salary' => 28000.00,
    ]);

    $line->refresh();
    expect((float) $line->salary_min)->toBe(12000.00)
        ->and((float) $line->salary_max)->toBe(16000.00);

    // Presenter still formats the snapshotted salary
    $presented = RequirementPresenter::toShow($req->fresh());
    expect($presented['lines'][0]['salary_range_formatted'])->toBe('AED 12,000.00 – 16,000.00');
});

test('11. repeat and duplicate copy original requirement salary snapshot, not position salary', function () {
    $req = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-TEST-011',
        'client_id' => $this->clientA->id,
        'project_id' => $this->projectA->id,
        'request_received_date' => now()->subDay(),
        'required_by_date' => now()->addDays(14),
        'priority' => 'normal',
        'status' => RequirementStatus::Open,
        'assigned_to' => $this->recruiterA->id,
        'created_by' => $this->requesterA->id,
        'updated_by' => $this->requesterA->id,
    ]);

    RecruitmentRequirementLine::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $req->id,
        'position_id' => $this->positionA->id,
        'required_headcount' => 1,
        'salary_min' => 9000.00, // Custom override in original
        'salary_max' => 11000.00,
        'salary_currency_code' => 'AED',
        'status' => RequirementLineStatus::Open,
    ]);

    // Alter position default
    $this->positionA->update([
        'min_salary' => 15000.00,
        'max_salary' => 20000.00,
    ]);

    // 1. Repeat requirement
    $repeatPayload = [
        'required_by_date' => now()->addDays(20)->toDateString(),
        'positions' => [
            [
                'position_id' => $this->positionA->id,
                'required_headcount' => 2,
            ],
        ],
    ];

    $this->actingAs($this->requesterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/repeat", $repeatPayload)
        ->assertRedirect();

    $repeatedReq = RecruitmentRequirement::query()->latest('id')->first();
    $repeatedLine = $repeatedReq->lines()->first();
    expect((float) $repeatedLine->salary_min)->toBe(9000.00)
        ->and((float) $repeatedLine->salary_max)->toBe(11000.00)
        ->and($repeatedLine->salary_currency_code)->toBe('AED');

    // 2. Duplicate separate batch
    $dupPayload = [
        'client_id' => $this->clientA->id,
        'project_id' => $this->projectA->id,
        'request_received_date' => now()->subDay()->toDateString(),
        'required_by_date' => now()->addDays(14)->toDateString(),
        'priority' => 'normal',
        'assigned_to' => $this->recruiterA->id,
        'submit_for_approval' => true,
        'ignore_duplicate_warning' => true,
        'positions' => [
            [
                'position_id' => $this->positionA->id,
                'required_headcount' => 1,
                'salary_min' => '9000.00',
                'salary_max' => '11000.00',
            ],
        ],
    ];

    $this->actingAs($this->requesterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post('/organization/recruitment/requirements', $dupPayload)
        ->assertRedirect();

    $dupReq = RecruitmentRequirement::query()->latest('id')->first();
    $dupLine = $dupReq->lines()->first();
    expect((float) $dupLine->salary_min)->toBe(9000.00)
        ->and((float) $dupLine->salary_max)->toBe(11000.00);
});

test('12. returned requirements can have salary corrected before resubmission', function () {
    $req = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-TEST-012',
        'client_id' => $this->clientA->id,
        'project_id' => $this->projectA->id,
        'request_received_date' => now()->subDay(),
        'required_by_date' => now()->addDays(14),
        'priority' => 'normal',
        'status' => RequirementStatus::Returned,
        'assigned_to' => $this->recruiterA->id,
        'created_by' => $this->requesterA->id,
        'updated_by' => $this->requesterA->id,
    ]);

    $line = RecruitmentRequirementLine::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $req->id,
        'position_id' => $this->positionA->id,
        'required_headcount' => 1,
        'salary_min' => 5000.00,
        'salary_max' => 6000.00,
        'salary_currency_code' => 'AED',
        'status' => RequirementLineStatus::Open,
    ]);

    $updatePayload = [
        'client_id' => $this->clientA->id,
        'project_id' => $this->projectA->id,
        'location' => 'Dubai Port',
        'request_received_date' => now()->subDay()->toDateString(),
        'required_by_date' => now()->addDays(14)->toDateString(),
        'priority' => 'normal',
        'assigned_to' => $this->recruiterA->id,
        'positions' => [
            [
                'id' => $line->id,
                'position_id' => $this->positionA->id,
                'required_headcount' => 1,
                'salary_min' => '7000.00',
                'salary_max' => '9000.00',
            ],
        ],
    ];

    $this->actingAs($this->requesterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->put("/organization/recruitment/requirements/{$req->id}", $updatePayload)
        ->assertRedirect();

    $line->refresh();
    expect((float) $line->salary_min)->toBe(7000.00)
        ->and((float) $line->salary_max)->toBe(9000.00);

    // Resubmission succeeds
    $this->actingAs($this->requesterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertRedirect();

    expect($req->fresh()->status)->toBe(RequirementStatus::PendingApproval);
});

test('13. unauthorized or non-editable workflow states cannot change salary', function () {
    $req = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-TEST-013',
        'client_id' => $this->clientA->id,
        'project_id' => $this->projectA->id,
        'request_received_date' => now()->subDay(),
        'required_by_date' => now()->addDays(14),
        'priority' => 'normal',
        'status' => RequirementStatus::Open, // Non-editable status
        'assigned_to' => $this->recruiterA->id,
        'created_by' => $this->requesterA->id,
        'updated_by' => $this->requesterA->id,
    ]);

    $line = RecruitmentRequirementLine::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $req->id,
        'position_id' => $this->positionA->id,
        'required_headcount' => 1,
        'salary_min' => 5000.00,
        'salary_max' => 6000.00,
        'salary_currency_code' => 'AED',
        'status' => RequirementLineStatus::Open,
    ]);

    $updatePayload = [
        'client_id' => $this->clientA->id,
        'project_id' => $this->projectA->id,
        'request_received_date' => now()->subDay()->toDateString(),
        'required_by_date' => now()->addDays(14)->toDateString(),
        'priority' => 'normal',
        'assigned_to' => $this->recruiterA->id,
        'positions' => [
            [
                'id' => $line->id,
                'position_id' => $this->positionA->id,
                'required_headcount' => 1,
                'salary_min' => '9999.00',
                'salary_max' => '9999.00',
            ],
        ],
    ];

    $this->actingAs($this->requesterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->put("/organization/recruitment/requirements/{$req->id}", $updatePayload)
        ->assertSessionHasErrors(['positions']);

    $line->refresh();
    expect((float) $line->salary_min)->toBe(5000.00);
});

test('14. cross-company position manipulation is rejected', function () {
    $payload = [
        'client_id' => $this->clientA->id,
        'project_id' => $this->projectA->id,
        'request_received_date' => now()->subDay()->toDateString(),
        'required_by_date' => now()->addDays(14)->toDateString(),
        'priority' => 'normal',
        'assigned_to' => $this->recruiterA->id,
        'submit_for_approval' => true,
        'ignore_duplicate_warning' => true,
        'positions' => [
            [
                'position_id' => $this->positionB->id, // Position belonging to Company B
                'required_headcount' => 1,
                'salary_min' => '20000.00',
                'salary_max' => '25000.00',
            ],
        ],
    ];

    $this->actingAs($this->requesterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post('/organization/recruitment/requirements', $payload)
        ->assertSessionHasErrors(['positions.0.position_id']);
});

test('15. existing historical requirement lines with null salary continue loading safely', function () {
    $req = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-HISTORICAL-001',
        'client_id' => $this->clientA->id,
        'project_id' => $this->projectA->id,
        'request_received_date' => now()->subDay(),
        'required_by_date' => now()->addDays(14),
        'priority' => 'normal',
        'status' => RequirementStatus::Open,
        'assigned_to' => $this->recruiterA->id,
        'created_by' => $this->requesterA->id,
        'updated_by' => $this->requesterA->id,
    ]);

    RecruitmentRequirementLine::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $req->id,
        'position_id' => $this->positionA->id,
        'required_headcount' => 1,
        'salary_min' => null,
        'salary_max' => null,
        'salary_currency_code' => null,
        'status' => RequirementLineStatus::Open,
    ]);

    $this->actingAs($this->requesterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get("/organization/recruitment/requirements/{$req->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/recruitment/requirements/show')
            ->where('requirement.lines.0.salary_min', null)
            ->where('requirement.lines.0.salary_max', null)
            ->where('requirement.lines.0.salary_range_formatted', 'Not specified')
        );
});

test('16. requirement detail and approval views display the stored range', function () {
    $req = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-TEST-016',
        'client_id' => $this->clientA->id,
        'project_id' => $this->projectA->id,
        'request_received_date' => now()->subDay(),
        'required_by_date' => now()->addDays(14),
        'priority' => 'normal',
        'status' => RequirementStatus::PendingApproval,
        'assigned_to' => $this->recruiterA->id,
        'created_by' => $this->requesterA->id,
        'updated_by' => $this->requesterA->id,
    ]);

    // Line 1: Range
    RecruitmentRequirementLine::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $req->id,
        'position_id' => $this->positionA->id,
        'required_headcount' => 1,
        'salary_min' => 4000.00,
        'salary_max' => 6000.00,
        'salary_currency_code' => 'AED',
        'status' => RequirementLineStatus::Open,
    ]);

    // Line 2: Single amount (equal min and max)
    RecruitmentRequirementLine::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $req->id,
        'position_id' => $this->positionNoSalary->id,
        'required_headcount' => 1,
        'salary_min' => 5000.00,
        'salary_max' => 5000.00,
        'salary_currency_code' => 'AED',
        'status' => RequirementLineStatus::Open,
    ]);

    $this->actingAs($this->requesterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get("/organization/recruitment/requirements/{$req->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/recruitment/requirements/show')
            ->where('requirement.lines.0.salary_range_formatted', 'AED 4,000.00 – 6,000.00')
            ->where('requirement.lines.1.salary_range_formatted', 'AED 5,000.00')
        );
});

test('17. activity logs record salary changes', function () {
    $req = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-TEST-017',
        'client_id' => $this->clientA->id,
        'project_id' => $this->projectA->id,
        'request_received_date' => now()->subDay(),
        'required_by_date' => now()->addDays(14),
        'priority' => 'normal',
        'status' => RequirementStatus::Draft,
        'assigned_to' => $this->recruiterA->id,
        'created_by' => $this->requesterA->id,
        'updated_by' => $this->requesterA->id,
    ]);

    $line = RecruitmentRequirementLine::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $req->id,
        'position_id' => $this->positionA->id,
        'required_headcount' => 1,
        'salary_min' => 5000.00,
        'salary_max' => 6000.00,
        'salary_currency_code' => 'AED',
        'status' => RequirementLineStatus::Open,
    ]);

    $updatePayload = [
        'client_id' => $this->clientA->id,
        'project_id' => $this->projectA->id,
        'request_received_date' => now()->subDay()->toDateString(),
        'required_by_date' => now()->addDays(14)->toDateString(),
        'priority' => 'normal',
        'assigned_to' => $this->recruiterA->id,
        'positions' => [
            [
                'id' => $line->id,
                'position_id' => $this->positionA->id,
                'required_headcount' => 1,
                'salary_min' => '7500.00',
                'salary_max' => '8500.00',
            ],
        ],
    ];

    $this->actingAs($this->requesterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->put("/organization/recruitment/requirements/{$req->id}", $updatePayload)
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $activity = Activity::forSubject($line)->latest('id')->first();
    expect($activity)->not->toBeNull()
        ->and((float) $activity->attribute_changes['attributes']['salary_min'])->toBe(7500.00)
        ->and((float) $activity->attribute_changes['attributes']['salary_max'])->toBe(8500.00);
});

test('18. recruiter approval email uses saved requirement salary snapshot, not current position salary', function () {
    Queue::fake();

    $req = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-TEST-018',
        'client_id' => $this->clientA->id,
        'project_id' => $this->projectA->id,
        'request_received_date' => now()->subDay(),
        'required_by_date' => now()->addDays(14),
        'priority' => 'normal',
        'status' => RequirementStatus::Draft,
        'assigned_to' => $this->recruiterA->id,
        'created_by' => $this->requesterA->id,
        'updated_by' => $this->requesterA->id,
    ]);

    RecruitmentRequirementLine::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $req->id,
        'position_id' => $this->positionA->id,
        'required_headcount' => 2,
        'salary_min' => 11000.00,
        'salary_max' => 14000.00,
        'salary_currency_code' => 'AED',
        'status' => RequirementLineStatus::Open,
    ]);

    // Position salary default changes in master data
    $this->positionA->update([
        'min_salary' => 99999.00,
        'max_salary' => 99999.00,
    ]);

    // Submit for approval
    $this->actingAs($this->requesterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    /** @var DeliverRequirementLifecycleEmailJob|null $pushedJob */
    $pushedJob = null;
    Queue::assertPushed(DeliverRequirementLifecycleEmailJob::class, function (DeliverRequirementLifecycleEmailJob $job) use (&$pushedJob) {
        $pushedJob = $job;

        return true;
    });

    expect($pushedJob)->not->toBeNull();

    Mail::fake();
    $pushedJob->handle();

    Mail::assertSent(RequirementSubmittedForApprovalMail::class, function (RequirementSubmittedForApprovalMail $mail) {
        $positionsRow = collect($mail->details)->firstWhere('label', 'Positions');

        return $positionsRow !== null
            && str_contains($positionsRow['value'], 'Chief Engineer × 2 (AED 11,000.00 – 14,000.00)')
            && ! str_contains($positionsRow['value'], '99,999.00');
    });
});
