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
use App\Support\Employees\EmployeeSmartSearchPromptGuard;
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
        ->assertJsonPath('applied.0.key', 'status:equals')
        ->assertJsonPath('applied.0.label', 'HR status')
        ->assertJsonPath('applied.0.value', 'Active')
        ->assertJsonPath('applied.1.key', 'department:equals')
        ->assertJsonPath('applied.1.value', 'Crewing')
        ->assertJsonPath('applied.2.key', 'nationality:equals')
        ->assertJsonPath('applied.2.value', 'Philippines')
        ->assertJsonPath('applied.3.key', 'rank:equals')
        ->assertJsonPath('applied.3.value', 'AB')
        ->assertJsonPath('unresolved', [])
        ->assertJsonPath('ambiguous', [])
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
        ->assertJsonPath('applied.0.key', 'status:equals')
        ->assertJsonPath('applied.0.value', 'On leave');
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
        ->assertJsonPath('applied.0.key', 'crew_status:equals')
        ->assertJsonPath('applied.0.value', 'On vessel');

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
        ->assertJsonPath('ambiguous.0.field', 'position')
        ->assertJsonPath('ambiguous.0.reason', 'ambiguous');
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
        array_merge(fakeSmartSearchIntent([
            'status' => 'active',
            'department' => 'Crewing',
        ]), [
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

    expect($payload)->toHaveKeys(['filters', 'applied', 'unresolved', 'ambiguous', 'unsupported'])
        ->and($payload)->not->toHaveKey('employees')
        ->and($payload)->not->toHaveKey('company_id')
        ->and($payload)->not->toHaveKey('labels')
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

    expect(array_keys($payload))->toEqualCanonicalizing(['filters', 'applied', 'unresolved', 'ambiguous', 'unsupported'])
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
        ->assertJsonPath('applied.0.value', 'Active');
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
    'missing criteria' => [json_encode(['unsupported_terms' => []])],
    'missing unsupported terms' => [json_encode(['criteria' => [], 'ambiguous_terms' => []])],
    'wrong field types' => [json_encode([
        'criteria' => ['active'],
        'ambiguous_terms' => [],
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
        'criteria' => [
            ['concept' => 'status', 'operator' => 'equals', 'value' => 'active'],
        ],
        'ambiguous_terms' => [],
        'unsupported_terms' => [],
        'company_id' => $fixtures['otherCompany']->id,
        'department_id' => $fixtures['otherDepartment']->id,
        'position_id' => $fixtures['otherPosition']->id,
        'filters' => ['status' => 'terminated'],
        'sql' => 'select * from employees',
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
            ->where('smart_search_available', true)
            ->missing('smart_search_enabled')
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
            ->where('smart_search_available', false)
            ->missing('smart_search_enabled')
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

test('smart search resolver maps emirates id missing and present completeness', function (string $operator) {
    enableEmployeeSmartSearch();
    $fixtures = makeEmployeeSmartSearchFixtures();

    EmployeeSmartSearchInterpreter::fake([
        fakeSmartSearchIntent([
            'criteria' => [[
                'concept' => 'emirates_id',
                'operator' => $operator,
                'value' => null,
            ]],
        ]),
    ]);

    interpretSmartSearch($fixtures['user'], $fixtures['company']->id, 'employees with emirates id')
        ->assertOk()
        ->assertJsonPath(
            $operator === 'missing' ? 'filters.missing_fields' : 'filters.present_fields',
            'emirates_id',
        )
        ->assertJsonPath('applied.0.key', 'emirates_id:'.$operator)
        ->assertJsonPath('applied.0.label', 'Emirates ID')
        ->assertJsonPath('applied.0.value', $operator === 'missing' ? 'Missing' : 'Present')
        ->assertJsonMissingPath('filters.emirates_id')
        ->assertJsonMissingPath('filters.emirates_id_presence');
})->with([
    'missing' => ['missing'],
    'present' => ['present'],
]);

test('smart search blocks an actual emirates id number before the provider is called', function () {
    enableEmployeeSmartSearch();
    $fixtures = makeEmployeeSmartSearchFixtures();

    EmployeeSmartSearchInterpreter::fake([
        fakeSmartSearchIntent(['status' => 'active']),
    ]);

    interpretSmartSearch(
        $fixtures['user'],
        $fixtures['company']->id,
        'employee with Emirates ID 784-2000-1234567-1',
    )->assertUnprocessable()
        ->assertJsonPath('errors.prompt.0', EmployeeSmartSearchPromptGuard::MESSAGE);

    EmployeeSmartSearchInterpreter::assertNeverPrompted();
});

test('interpret requests cannot submit emirates id values', function () {
    enableEmployeeSmartSearch();
    $fixtures = makeEmployeeSmartSearchFixtures();

    EmployeeSmartSearchInterpreter::fake([fakeSmartSearchIntent(['status' => 'active'])]);

    interpretSmartSearch($fixtures['user'], $fixtures['company']->id, 'AB', [
        'emirates_id' => '784-1234-1234567-1',
        'emirates_id_presence' => 'missing',
        'missing_fields' => 'salary',
    ])->assertUnprocessable();

    EmployeeSmartSearchInterpreter::assertNeverPrompted();
});

test('smart search interpretation is rate limited per authenticated user', function () {
    enableEmployeeSmartSearch();
    $fixtures = makeEmployeeSmartSearchFixtures();

    EmployeeSmartSearchInterpreter::fake(fn () => fakeSmartSearchIntent(['status' => 'active']));

    for ($i = 0; $i < 30; $i++) {
        interpretSmartSearch($fixtures['user'], $fixtures['company']->id, 'AB')
            ->assertOk();
    }

    interpretSmartSearch($fixtures['user'], $fixtures['company']->id, 'AB')
        ->assertTooManyRequests();
});

test('blank stored model uses the fast default at runtime', function () {
    enableEmployeeSmartSearch();
    $fixtures = makeEmployeeSmartSearchFixtures();

    EmployeeSmartSearchInterpreter::fake([
        fakeSmartSearchIntent(['status' => 'active']),
    ]);

    interpretSmartSearch($fixtures['user'], $fixtures['company']->id, 'active crew')->assertOk();

    EmployeeSmartSearchInterpreter::assertPrompted(function (AgentPrompt $prompt): bool {
        return $prompt->model === 'gpt-5.6-luna';
    });
});

test('stored model still overrides the fast default', function () {
    enableEmployeeSmartSearch('test-openai-key', [
        'openai_model' => 'gpt-explicit-override',
    ]);
    $fixtures = makeEmployeeSmartSearchFixtures();

    EmployeeSmartSearchInterpreter::fake([
        fakeSmartSearchIntent(['status' => 'active']),
    ]);

    interpretSmartSearch($fixtures['user'], $fixtures['company']->id, 'active crew')->assertOk();

    EmployeeSmartSearchInterpreter::assertPrompted(function (AgentPrompt $prompt): bool {
        return $prompt->model === 'gpt-explicit-override';
    });
});

test('all statuses are returned as an explicit directory filter', function () {
    enableEmployeeSmartSearch();
    $fixtures = makeEmployeeSmartSearchFixtures();

    EmployeeSmartSearchInterpreter::fake([
        fakeSmartSearchIntent(['status' => 'all']),
    ]);

    interpretSmartSearch($fixtures['user'], $fixtures['company']->id, 'all employees')
        ->assertOk()
        ->assertJsonPath('filters.status', 'all')
        ->assertJsonPath('applied.0.key', 'status:equals')
        ->assertJsonPath('applied.0.value', 'All statuses');
});

test('composite email missing is returned as a generic completeness filter', function () {
    enableEmployeeSmartSearch();
    $fixtures = makeEmployeeSmartSearchFixtures();

    EmployeeSmartSearchInterpreter::fake([
        fakeSmartSearchIntent([
            'criteria' => [[
                'concept' => 'email',
                'operator' => 'missing',
                'value' => null,
            ]],
        ]),
    ]);

    interpretSmartSearch($fixtures['user'], $fixtures['company']->id, 'employees without email')
        ->assertOk()
        ->assertJsonPath('filters.missing_fields', 'email')
        ->assertJsonPath('applied.0.key', 'email:missing')
        ->assertJsonPath('applied.0.label', 'Email')
        ->assertJsonPath('applied.0.value', 'Missing')
        ->assertJsonMissingPath('filters.work_email');
});

test('department codes resolve against the current company only', function () {
    enableEmployeeSmartSearch();
    $fixtures = makeEmployeeSmartSearchFixtures();

    EmployeeSmartSearchInterpreter::fake([
        fakeSmartSearchIntent(['department' => 'CRW']),
    ]);

    interpretSmartSearch($fixtures['user'], $fixtures['company']->id, 'CRW employees')
        ->assertOk()
        ->assertJsonPath('filters.department_id', (string) $fixtures['department']->id);
});

test('rank aliases resolve to the trusted canonical rank name', function () {
    enableEmployeeSmartSearch();
    $fixtures = makeEmployeeSmartSearchFixtures();

    $fixtures['rank']->update(['name' => 'Able Seaman']);

    EmployeeSmartSearchInterpreter::fake([
        fakeSmartSearchIntent(['rank' => 'AB']),
    ]);

    interpretSmartSearch($fixtures['user'], $fixtures['company']->id, 'AB crew')
        ->assertOk()
        ->assertJsonPath('filters.rank_id', (string) $fixtures['rank']->id)
        ->assertJsonPath('applied.0.value', 'Able Seaman');
});

test('contradictory criteria are not applied', function () {
    enableEmployeeSmartSearch();
    $fixtures = makeEmployeeSmartSearchFixtures();

    EmployeeSmartSearchInterpreter::fake([
        fakeSmartSearchIntent([
            'criteria' => [
                ['concept' => 'nationality', 'operator' => 'equals', 'value' => 'Philippines'],
                ['concept' => 'nationality', 'operator' => 'missing', 'value' => null],
            ],
        ]),
    ]);

    interpretSmartSearch($fixtures['user'], $fixtures['company']->id, 'Indian employees with no nationality')
        ->assertOk()
        ->assertJsonPath('filters', [])
        ->assertJsonPath('applied', [])
        ->assertJsonPath('ambiguous.0.reason', 'conflict');
});

test('presence-only prompts are not blocked by the privacy guard', function (string $prompt) {
    enableEmployeeSmartSearch();
    $fixtures = makeEmployeeSmartSearchFixtures();

    EmployeeSmartSearchInterpreter::fake([
        fakeSmartSearchIntent([
            'criteria' => [[
                'concept' => 'email',
                'operator' => 'missing',
                'value' => null,
            ]],
        ]),
    ]);

    interpretSmartSearch($fixtures['user'], $fixtures['company']->id, $prompt)->assertOk();

    EmployeeSmartSearchInterpreter::assertPrompted(
        fn (AgentPrompt $agentPrompt): bool => $agentPrompt->prompt === $prompt,
    );
})->with([
    'without email' => ['employees without email'],
    'missing emirates id' => ['employees missing Emirates ID'],
    'without phone' => ['employees without phone'],
    'with passport' => ['employees with passport'],
    'under age' => ['employees under 30'],
    'without manager' => ['employees without manager'],
    'under department' => ['employees under Crewing department'],
]);

test('named person lookups are blocked before the provider is called', function (string $prompt) {
    enableEmployeeSmartSearch();
    $fixtures = makeEmployeeSmartSearchFixtures();

    EmployeeSmartSearchInterpreter::fake([fakeSmartSearchIntent(['status' => 'active'])]);

    interpretSmartSearch($fixtures['user'], $fixtures['company']->id, $prompt)
        ->assertUnprocessable()
        ->assertJsonPath('errors.prompt.0', EmployeeSmartSearchPromptGuard::MESSAGE);

    EmployeeSmartSearchInterpreter::assertNeverPrompted();
})->with([
    'named title case' => ['employee named Ahmed Khan'],
    'under person' => ['employees under Ahmed'],
    'managed by' => ['employees managed by Ahmed Khan'],
    'reporting to lowercase' => ['employees reporting to ahmed'],
    'who report to' => ['employees who report to mohammed'],
    'with manager' => ['employees with manager John'],
    'named lowercase' => ['employee named ahmed'],
    'called lowercase' => ['employee called john smith'],
    'name is lowercase' => ['name is mohammed'],
]);

test('enabled but unusable provider config hides smart search without leaking credentials', function () {
    enableEmployeeSmartSearch('');
    $fixtures = makeEmployeeSmartSearchFixtures();

    $response = test()->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get('/organization/employees')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employees')
            ->where('smart_search_available', false)
            ->missing('smart_search_enabled')
            ->missing('has_api_key')
            ->missing('provider')
            ->missing('model'));

    expect($response->getContent())->not->toContain('test-openai-key');
});

test('structurally incomplete nested criteria fail closed', function () {
    enableEmployeeSmartSearch(null, [
        'provider' => AiSettingsService::PROVIDER_OPENROUTER,
        'openrouter_api_key' => 'sk-or-test-key',
    ]);
    $fixtures = makeEmployeeSmartSearchFixtures();

    fakeOpenRouterSmartSearchContent(json_encode([
        'criteria' => [[
            'concept' => 'status',
            'operator' => 'equals',
        ]],
        'ambiguous_terms' => [],
        'unsupported_terms' => [],
    ]));
    Http::preventStrayRequests();

    interpretSmartSearch($fixtures['user'], $fixtures['company']->id, 'active crew')
        ->assertStatus(503)
        ->assertJsonMissingPath('filters');
});

test('arbitrary AI concepts cannot become filters', function () {
    enableEmployeeSmartSearch();
    $fixtures = makeEmployeeSmartSearchFixtures();

    EmployeeSmartSearchInterpreter::fake([
        fakeSmartSearchIntent([
            'criteria' => [[
                'concept' => 'salary',
                'operator' => 'equals',
                'value' => '9000',
            ]],
            'unsupported_terms' => [],
        ]),
    ]);

    interpretSmartSearch($fixtures['user'], $fixtures['company']->id, 'high salary employees')
        ->assertStatus(503)
        ->assertJsonMissingPath('filters');
});
