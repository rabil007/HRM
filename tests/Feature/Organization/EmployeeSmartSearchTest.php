<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Rank;
use App\Models\User;
use App\Services\EmployeeSmartSearchInterpreter;
use App\Services\Settings\AiSettingsService;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Prompts\AgentPrompt;

/**
 * @return array{
 *     user: User,
 *     company: Company,
 *     otherCompany: Company,
 *     department: Department,
 *     otherDepartment: Department,
 *     position: Position,
 *     otherPosition: Position,
 *     country: Country,
 *     rank: Rank
 * }
 */
function makeEmployeeSmartSearchFixtures(): array
{
    ['user' => $user, 'companyA' => $company, 'companyB' => $otherCompany] = makeCompanyAuthorizationPair();

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Crewing',
        'code' => 'CRW',
        'status' => 'active',
    ]);

    $otherDepartment = Department::query()->create([
        'company_id' => $otherCompany->id,
        'name' => 'Crewing',
        'code' => 'CRW',
        'status' => 'active',
    ]);

    $position = Position::query()->create([
        'company_id' => $company->id,
        'department_id' => $department->id,
        'title' => 'Able Seaman',
        'status' => 'active',
    ]);

    $otherPosition = Position::query()->create([
        'company_id' => $otherCompany->id,
        'department_id' => $otherDepartment->id,
        'title' => 'Able Seaman',
        'status' => 'active',
    ]);

    $country = Country::query()->create([
        'code' => 'PHL',
        'name' => 'Philippines',
        'dial_code' => '+63',
        'is_active' => true,
    ]);

    $rank = Rank::query()->create([
        'name' => 'AB',
        'is_active' => true,
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    return compact(
        'user',
        'company',
        'otherCompany',
        'department',
        'otherDepartment',
        'position',
        'otherPosition',
        'country',
        'rank',
    );
}

test('guests cannot interpret employee smart search', function () {
    EmployeeSmartSearchInterpreter::fake();

    $this->postJson(route('organization.employees.smart-search.interpret'), [
        'prompt' => 'active crew in Crewing',
    ])->assertUnauthorized();

    EmployeeSmartSearchInterpreter::assertNeverPrompted();
});

test('authorized employee viewer can interpret a valid prompt', function () {
    enableEmployeeSmartSearch();
    $fixtures = makeEmployeeSmartSearchFixtures();

    EmployeeSmartSearchInterpreter::fake([
        fakeSmartSearchIntent([
            'status' => 'active',
            'department' => 'Crewing',
            'nationality' => 'Philippines',
            'rank' => 'AB',
        ]),
    ]);

    $response = interpretSmartSearch(
        $fixtures['user'],
        $fixtures['company']->id,
        'active Filipino AB crew in Crewing',
    )->assertOk();

    $response->assertJsonPath('filters.status', 'active')
        ->assertJsonPath('filters.department_id', (string) $fixtures['department']->id)
        ->assertJsonPath('filters.nationality_id', (string) $fixtures['country']->id)
        ->assertJsonPath('filters.rank_id', (string) $fixtures['rank']->id)
        ->assertJsonPath('labels.status', 'Active')
        ->assertJsonPath('labels.department', 'Crewing')
        ->assertJsonPath('labels.nationality', 'Philippines')
        ->assertJsonPath('labels.rank', 'AB')
        ->assertJsonPath('unresolved', [])
        ->assertJsonPath('unsupported', []);

    expect(array_keys($response->json('filters')))->toEqualCanonicalizing([
        'status',
        'department_id',
        'nationality_id',
        'rank_id',
    ]);

    EmployeeSmartSearchInterpreter::assertPrompted(function (AgentPrompt $prompt) use ($fixtures): bool {
        $instructions = (string) $prompt->agent->instructions();

        return $prompt->prompt === 'active Filipino AB crew in Crewing'
            && ! str_contains($prompt->prompt, (string) $fixtures['department']->id)
            && str_contains($instructions, 'untrusted')
            && ! str_contains($instructions, 'Able Seaman')
            && ! str_contains(mb_strtolower($instructions), 'salary');
    });
});

test('request without employees.view is forbidden and does not invoke AI', function () {
    enableEmployeeSmartSearch();
    ['user' => $user, 'companyA' => $company] = makeCompanyAuthorizationPair();

    EmployeeSmartSearchInterpreter::fake([fakeSmartSearchIntent(['status' => 'active'])]);

    interpretSmartSearch($user, $company->id, 'active crew')->assertForbidden();

    EmployeeSmartSearchInterpreter::assertNeverPrompted();
});

test('trusted current company is used and client company_id is rejected', function () {
    enableEmployeeSmartSearch();
    $fixtures = makeEmployeeSmartSearchFixtures();

    EmployeeSmartSearchInterpreter::fake([
        fakeSmartSearchIntent(['department' => 'Crewing']),
    ]);

    interpretSmartSearch(
        $fixtures['user'],
        $fixtures['company']->id,
        'crew in Crewing',
        ['company_id' => $fixtures['otherCompany']->id],
    )->assertUnprocessable();

    EmployeeSmartSearchInterpreter::assertNeverPrompted();

    interpretSmartSearch($fixtures['user'], $fixtures['company']->id, 'crew in Crewing')
        ->assertOk()
        ->assertJsonPath('filters.department_id', (string) $fixtures['department']->id)
        ->assertJsonMissingPath('company_id');

    expect($fixtures['department']->id)->not->toBe($fixtures['otherDepartment']->id);
});

test('client provider and API key cannot influence smart search', function () {
    enableEmployeeSmartSearch();
    $fixtures = makeEmployeeSmartSearchFixtures();

    EmployeeSmartSearchInterpreter::fake([
        fakeSmartSearchIntent(['department' => 'Crewing']),
    ]);

    interpretSmartSearch(
        $fixtures['user'],
        $fixtures['company']->id,
        'crew in Crewing',
        [
            'provider' => 'openrouter',
            'openai_api_key' => 'client-key',
        ],
    )->assertUnprocessable();

    EmployeeSmartSearchInterpreter::assertNeverPrompted();
});

test('department and position resolution is isolated to the current company', function () {
    enableEmployeeSmartSearch();
    $fixtures = makeEmployeeSmartSearchFixtures();

    EmployeeSmartSearchInterpreter::fake([
        fakeSmartSearchIntent([
            'department' => 'Crewing',
            'position' => 'Able Seaman',
        ]),
    ]);

    interpretSmartSearch($fixtures['user'], $fixtures['company']->id, 'Able Seaman in Crewing')
        ->assertOk()
        ->assertJsonPath('filters.department_id', (string) $fixtures['department']->id)
        ->assertJsonPath('filters.position_id', (string) $fixtures['position']->id)
        ->assertJsonMissing(['filters' => [
            'department_id' => (string) $fixtures['otherDepartment']->id,
            'position_id' => (string) $fixtures['otherPosition']->id,
        ]]);
});

test('country resolution uses active global master data only', function () {
    enableEmployeeSmartSearch();
    $fixtures = makeEmployeeSmartSearchFixtures();

    $inactive = Country::query()->create([
        'code' => 'INA',
        'name' => 'Inactive Land',
        'dial_code' => '+00',
        'is_active' => false,
    ]);

    EmployeeSmartSearchInterpreter::fake([
        fakeSmartSearchIntent(['nationality' => 'Inactive Land']),
    ]);

    interpretSmartSearch($fixtures['user'], $fixtures['company']->id, 'crew from Inactive Land')
        ->assertOk()
        ->assertJsonPath('filters', [])
        ->assertJsonPath('unresolved.0.field', 'nationality')
        ->assertJsonPath('unresolved.0.term', 'Inactive Land')
        ->assertJsonPath('unresolved.0.reason', 'not_found');

    expect($inactive->is_active)->toBeFalse();
});

test('rank resolution uses active global master data only', function () {
    enableEmployeeSmartSearch();
    $fixtures = makeEmployeeSmartSearchFixtures();

    $inactiveRank = Rank::query()->create([
        'name' => 'OS',
        'is_active' => false,
    ]);

    EmployeeSmartSearchInterpreter::fake([
        fakeSmartSearchIntent(['rank' => 'OS']),
    ]);

    interpretSmartSearch($fixtures['user'], $fixtures['company']->id, 'OS crew')
        ->assertOk()
        ->assertJsonMissingPath('filters.rank_id')
        ->assertJsonPath('unresolved.0.field', 'rank')
        ->assertJsonPath('unresolved.0.reason', 'not_found');

    expect($inactiveRank->is_active)->toBeFalse();
});

test('canonical HR status is returned correctly', function () {
    enableEmployeeSmartSearch();
    $fixtures = makeEmployeeSmartSearchFixtures();

    EmployeeSmartSearchInterpreter::fake([
        fakeSmartSearchIntent(['status' => 'on_leave']),
    ]);

    interpretSmartSearch($fixtures['user'], $fixtures['company']->id, 'employees on leave')
        ->assertOk()
        ->assertJsonPath('filters.status', 'on_leave')
        ->assertJsonPath('labels.status', 'On leave');
});

test('canonical crew status is validated using existing crew-status rules', function () {
    enableEmployeeSmartSearch();
    $fixtures = makeEmployeeSmartSearchFixtures();

    EmployeeSmartSearchInterpreter::fake([
        fakeSmartSearchIntent(['crew_status' => 'on_vessel']),
    ]);

    interpretSmartSearch($fixtures['user'], $fixtures['company']->id, 'crew on vessel')
        ->assertOk()
        ->assertJsonPath('filters.crew_status', 'on_vessel')
        ->assertJsonPath('labels.crew_status', 'On vessel');

    EmployeeSmartSearchInterpreter::fake([
        fakeSmartSearchIntent(['crew_status' => 'at_sea']),
    ]);

    interpretSmartSearch($fixtures['user'], $fixtures['company']->id, 'crew at sea')
        ->assertOk()
        ->assertJsonMissingPath('filters.crew_status')
        ->assertJsonPath('unresolved.0.field', 'crew_status')
        ->assertJsonPath('unresolved.0.reason', 'not_found');
});

test('unresolved supported values are not silently applied', function () {
    enableEmployeeSmartSearch();
    $fixtures = makeEmployeeSmartSearchFixtures();

    EmployeeSmartSearchInterpreter::fake([
        fakeSmartSearchIntent([
            'status' => 'active',
            'department' => 'Unknown Department',
        ]),
    ]);

    interpretSmartSearch($fixtures['user'], $fixtures['company']->id, 'active crew in Unknown Department')
        ->assertOk()
        ->assertJsonPath('filters.status', 'active')
        ->assertJsonMissingPath('filters.department_id')
        ->assertJsonPath('unresolved.0.field', 'department')
        ->assertJsonPath('unresolved.0.term', 'Unknown Department')
        ->assertJsonPath('unresolved.0.reason', 'not_found');
});

test('ambiguous values are not guessed', function () {
    enableEmployeeSmartSearch();
    $fixtures = makeEmployeeSmartSearchFixtures();

    Position::query()->create([
        'company_id' => $fixtures['company']->id,
        'department_id' => $fixtures['department']->id,
        'title' => 'Officer',
        'status' => 'active',
    ]);

    $otherLocalDepartment = Department::query()->create([
        'company_id' => $fixtures['company']->id,
        'name' => 'Deck',
        'code' => 'DECK',
        'status' => 'active',
    ]);

    Position::query()->create([
        'company_id' => $fixtures['company']->id,
        'department_id' => $otherLocalDepartment->id,
        'title' => 'Officer',
        'status' => 'active',
    ]);

    EmployeeSmartSearchInterpreter::fake([
        fakeSmartSearchIntent(['position' => 'Officer']),
    ]);

    interpretSmartSearch($fixtures['user'], $fixtures['company']->id, 'Officer crew')
        ->assertOk()
        ->assertJsonMissingPath('filters.position_id')
        ->assertJsonPath('unresolved.0.field', 'position')
        ->assertJsonPath('unresolved.0.reason', 'ambiguous');
});

test('unsupported concepts are reported rather than fabricated', function () {
    enableEmployeeSmartSearch();
    $fixtures = makeEmployeeSmartSearchFixtures();

    EmployeeSmartSearchInterpreter::fake([
        fakeSmartSearchIntent([
            'rank' => 'AB',
            'unsupported_terms' => ['valid STCW'],
        ]),
    ]);

    interpretSmartSearch($fixtures['user'], $fixtures['company']->id, 'AB crew with valid STCW')
        ->assertOk()
        ->assertJsonPath('filters.rank_id', (string) $fixtures['rank']->id)
        ->assertJsonPath('unsupported.0', 'valid STCW')
        ->assertJsonMissingPath('filters.search');
});

test('malformed extra AI fields cannot become query filters', function () {
    enableEmployeeSmartSearch();
    $fixtures = makeEmployeeSmartSearchFixtures();

    Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Secret Employee',
        'employee_no' => 'SEC-001',
        'personal_email' => 'secret@example.test',
    ]);

    EmployeeSmartSearchInterpreter::fake([
        fakeSmartSearchIntent([
            'status' => 'active',
            'department' => 'Crewing',
            'company_id' => $fixtures['otherCompany']->id,
            'department_id' => $fixtures['otherDepartment']->id,
            'employees' => [
                [
                    'id' => 99,
                    'name' => 'Secret Employee',
                    'salary' => '9000',
                    'iban' => 'AE000000000000000000000',
                ],
            ],
        ]),
    ]);

    $response = interpretSmartSearch(
        $fixtures['user'],
        $fixtures['company']->id,
        'active crew in Crewing',
    )->assertOk();

    $payload = $response->json();

    expect($payload)->toHaveKeys(['filters', 'labels', 'unresolved', 'unsupported'])
        ->and($payload)->not->toHaveKey('employees')
        ->and($payload)->not->toHaveKey('company_id')
        ->and($payload['filters'])->toBe([
            'department_id' => (string) $fixtures['department']->id,
            'status' => 'active',
        ])
        ->and(json_encode($payload))->not->toContain('Secret Employee')
        ->and(json_encode($payload))->not->toContain('9000')
        ->and(json_encode($payload))->not->toContain('AE000000000000000000000');
});

test('prompt validation enforces min and max length', function () {
    enableEmployeeSmartSearch();
    $fixtures = makeEmployeeSmartSearchFixtures();

    EmployeeSmartSearchInterpreter::fake([fakeSmartSearchIntent(['status' => 'active'])]);

    interpretSmartSearch($fixtures['user'], $fixtures['company']->id, 'a')
        ->assertUnprocessable();

    interpretSmartSearch($fixtures['user'], $fixtures['company']->id, str_repeat('a', 201))
        ->assertUnprocessable();

    EmployeeSmartSearchInterpreter::assertNeverPrompted();
});

test('feature-disabled requests fail before an AI call', function () {
    $fixtures = makeEmployeeSmartSearchFixtures();

    storePlatformAiSettings([
        'enabled' => false,
        'openai_api_key' => 'test-openai-key',
    ]);

    config(['employee-smart-search.enabled' => true]);

    EmployeeSmartSearchInterpreter::fake([fakeSmartSearchIntent(['status' => 'active'])]);

    interpretSmartSearch($fixtures['user'], $fixtures['company']->id, 'active crew')
        ->assertForbidden()
        ->assertJsonPath('message', 'Employee smart search is not enabled.');

    EmployeeSmartSearchInterpreter::assertNeverPrompted();
});

test('missing provider credentials return a safe failure without invoking AI', function () {
    enableEmployeeSmartSearch('');
    $fixtures = makeEmployeeSmartSearchFixtures();

    EmployeeSmartSearchInterpreter::fake([fakeSmartSearchIntent(['status' => 'active'])]);

    interpretSmartSearch($fixtures['user'], $fixtures['company']->id, 'active crew')
        ->assertStatus(503)
        ->assertJsonPath('message', 'Employee smart search is temporarily unavailable.')
        ->assertJsonMissingPath('employees');

    EmployeeSmartSearchInterpreter::assertNeverPrompted();
});

test('AI provider failure returns a safe failure response', function () {
    enableEmployeeSmartSearch();
    $fixtures = makeEmployeeSmartSearchFixtures();

    EmployeeSmartSearchInterpreter::fake(function (): array {
        throw new RuntimeException('provider timeout with key sk-secret');
    });

    $response = interpretSmartSearch($fixtures['user'], $fixtures['company']->id, 'active crew')
        ->assertStatus(503)
        ->assertJsonPath('message', 'Employee smart search is temporarily unavailable.');

    $payload = json_encode($response->json());

    expect($payload)->not->toContain('sk-secret')
        ->and($payload)->not->toContain('provider timeout')
        ->and($response->json())->not->toHaveKey('employees')
        ->and($response->json())->not->toHaveKey('trace');
});

test('successful response contains no employee records or sensitive fields', function () {
    enableEmployeeSmartSearch();
    $fixtures = makeEmployeeSmartSearchFixtures();

    Employee::factory()->forCompany($fixtures['company'])->create([
        'name' => 'Do Not Leak',
        'phone' => '0500000000',
        'personal_email' => 'noleak@example.test',
    ]);

    EmployeeSmartSearchInterpreter::fake([
        fakeSmartSearchIntent(['status' => 'active']),
    ]);

    $response = interpretSmartSearch($fixtures['user'], $fixtures['company']->id, 'active employees')
        ->assertOk();

    $payload = $response->json();

    expect(array_keys($payload))->toEqualCanonicalizing(['filters', 'labels', 'unresolved', 'unsupported'])
        ->and(json_encode($payload))->not->toContain('Do Not Leak')
        ->and(json_encode($payload))->not->toContain('0500000000')
        ->and(json_encode($payload))->not->toContain('noleak@example.test')
        ->and($payload)->not->toHaveKey('company_id');
});

test('raw and fenced OpenRouter JSON still resolve employee smart search filters', function (string $content) {
    enableEmployeeSmartSearch(null, [
        'provider' => AiSettingsService::PROVIDER_OPENROUTER,
        'openrouter_api_key' => 'sk-or-test-key',
    ]);
    $fixtures = makeEmployeeSmartSearchFixtures();

    fakeOpenRouterSmartSearchContent($content);
    Http::preventStrayRequests();

    interpretSmartSearch($fixtures['user'], $fixtures['company']->id, 'active crew')
        ->assertOk()
        ->assertJsonPath('filters.status', 'active')
        ->assertJsonPath('labels.status', 'Active');
})->with([
    'raw json' => [json_encode(fakeSmartSearchIntent(['status' => 'active']))],
    'fenced json' => ["```json\n".json_encode(fakeSmartSearchIntent(['status' => 'active']))."\n```"],
]);

test('malformed AI structured output fails closed', function (string $content) {
    enableEmployeeSmartSearch(null, [
        'provider' => AiSettingsService::PROVIDER_OPENROUTER,
        'openrouter_api_key' => 'sk-or-test-key',
    ]);
    $fixtures = makeEmployeeSmartSearchFixtures();

    fakeOpenRouterSmartSearchContent($content);
    Http::preventStrayRequests();

    $response = interpretSmartSearch($fixtures['user'], $fixtures['company']->id, 'active crew')
        ->assertStatus(503)
        ->assertJsonPath('message', 'Employee smart search is temporarily unavailable.')
        ->assertJsonMissingPath('employees')
        ->assertJsonMissingPath('filters');

    $payload = json_encode($response->json());

    expect($payload)->not->toContain('sk-or-test-key')
        ->not->toContain('secret-should-not-leak');
})->with([
    'empty content' => [''],
    'invalid json' => ['not json secret-should-not-leak'],
    'json array' => ['[]'],
    'missing schema' => [json_encode(['hello' => 'world', 'secret' => 'secret-should-not-leak'])],
    'missing unsupported terms' => [json_encode(['status' => 'active'])],
    'wrong field types' => [json_encode([
        'status' => ['active'],
        'unsupported_terms' => [],
        'secret' => 'secret-should-not-leak',
    ])],
]);

test('unexpected extra AI fields cannot become employee filters', function () {
    enableEmployeeSmartSearch(null, [
        'provider' => AiSettingsService::PROVIDER_OPENROUTER,
        'openrouter_api_key' => 'sk-or-test-key',
    ]);
    $fixtures = makeEmployeeSmartSearchFixtures();

    fakeOpenRouterSmartSearchContent(json_encode([
        'status' => 'active',
        'company_id' => $fixtures['otherCompany']->id,
        'department_id' => $fixtures['otherDepartment']->id,
        'position_id' => $fixtures['otherPosition']->id,
        'filters' => ['status' => 'terminated'],
        'sql' => 'select * from employees',
        'unsupported_terms' => [],
    ]));
    Http::preventStrayRequests();

    $response = interpretSmartSearch($fixtures['user'], $fixtures['company']->id, 'active crew')
        ->assertOk()
        ->assertJsonPath('filters.status', 'active')
        ->assertJsonMissingPath('filters.department_id')
        ->assertJsonMissingPath('filters.position_id');

    $payload = json_encode($response->json());

    expect($payload)->not->toContain('select * from employees')
        ->not->toContain('sk-or-test-key')
        ->and($response->json())->not->toHaveKey('company_id')
        ->and($response->json('filters.status'))->not->toBe('terminated');
});

test('employee directory receives smart_search_enabled when the platform setting is on', function () {
    enableEmployeeSmartSearch('sk-secret-openai-key', [
        'openai_model' => 'gpt-leaky-model',
        'openrouter_api_key' => 'sk-secret-openrouter-key',
        'openrouter_model' => 'openrouter/leaky-model',
    ]);
    $fixtures = makeEmployeeSmartSearchFixtures();

    $response = test()->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get('/organization/employees')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employees')
            ->where('smart_search_enabled', true)
            ->missing('ai')
            ->missing('provider')
            ->missing('model')
            ->missing('openai')
            ->missing('openrouter')
            ->missing('openai_api_key')
            ->missing('openrouter_api_key')
            ->missing('ai_openai_api_key')
            ->missing('ai_openrouter_api_key')
            ->missing('has_api_key'));

    expect($response->getContent())
        ->not->toContain('sk-secret-openai-key')
        ->not->toContain('sk-secret-openrouter-key')
        ->not->toContain('gpt-leaky-model')
        ->not->toContain('openrouter/leaky-model');
});

test('employee directory receives smart_search_enabled false when disabled', function () {
    storePlatformAiSettings([
        'enabled' => false,
        'provider' => AiSettingsService::PROVIDER_OPENAI,
        'openai_api_key' => 'sk-secret-disabled-openai',
        'openai_model' => 'gpt-disabled-model',
        'openrouter_api_key' => 'sk-secret-disabled-openrouter',
        'openrouter_model' => 'openrouter/disabled-model',
    ]);
    $fixtures = makeEmployeeSmartSearchFixtures();

    $response = test()->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get('/organization/employees')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employees')
            ->where('smart_search_enabled', false)
            ->missing('ai')
            ->missing('provider')
            ->missing('openai_api_key')
            ->missing('openrouter_api_key'));

    expect($response->getContent())
        ->not->toContain('sk-secret-disabled-openai')
        ->not->toContain('sk-secret-disabled-openrouter')
        ->not->toContain('gpt-disabled-model');
});

test('employee directory authorization is unchanged when smart search is enabled', function () {
    enableEmployeeSmartSearch();

    $this->get('/organization/employees')->assertRedirect(route('login'));

    ['user' => $user, 'companyA' => $company] = makeCompanyAuthorizationPair();
    grantCompanyPermissions($user, $company, ['branches.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get('/organization/employees')
        ->assertForbidden();
});
