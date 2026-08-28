<?php

use App\Enums\DocumentWorkflowPresetStatus;
use App\Enums\DocumentWorkflowRequestStatus;
use App\Models\Company;
use App\Models\Department;
use App\Models\DocumentInstanceVersion;
use App\Models\DocumentWorkflowPreset;
use App\Models\DocumentWorkflowRequest;
use App\Models\DocumentWorkflowTask;
use App\Models\Employee;
use App\Models\User;
use App\Support\Documents\Workflow\Actions\DeactivateDocumentWorkflowPreset;
use App\Support\Documents\Workflow\Actions\StoreDocumentWorkflowPreset;
use App\Support\Documents\Workflow\Actions\UpdateDocumentWorkflowPreset;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/../../Support/document-workflow-fixtures.php';

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake('local');
});

function makePresetStages(array $overrides = []): array
{
    return array_replace([
        [
            'action' => 'review',
            'completion_rule' => 'all',
            'targets' => [[
                'target_type' => 'department_manager',
            ]],
        ],
        [
            'action' => 'approve',
            'completion_rule' => 'any',
            'targets' => [[
                'target_type' => 'specific_user',
                'target_user_id' => null,
            ]],
        ],
    ], $overrides);
}

function grantPresetPermissions(User $user, Company $company, array $permissions): void
{
    foreach ($permissions as $permission) {
        giveCompanyPermission($user, $company, $permission);
    }
}

function grantLegacyHomeCompanyPermission(User $user, Company $company, string $permission): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $user->givePermissionTo(Permission::query()->firstOrCreate([
        'name' => $permission,
        'guard_name' => 'web',
    ]));

    if (in_array($permission, ['documents.requests.review', 'documents.requests.approve'], true)) {
        $user->givePermissionTo(Permission::query()->firstOrCreate([
            'name' => 'documents.requests.view',
            'guard_name' => 'web',
        ]));
    }
}

test('users without documents.workflow-presets.view cannot access preset index', function () {
    $company = makeDocumentFixtures()['company'];
    $user = User::factory()->create();
    grantCompanyPermissions($user, $company, ['documents.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.workflow-presets'))
        ->assertForbidden();
});

test('creates workflow preset with stages and targets', function () {
    $company = makeDocumentFixtures()['company'];
    $admin = User::factory()->create();
    $approver = User::factory()->create();

    grantPresetPermissions($admin, $company, [
        'documents.workflow-presets.create',
        'documents.workflow-presets.view',
    ]);
    giveCompanyPermission($approver, $company, 'documents.requests.approve');

    $this->actingAs($admin)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.workflow-presets.store'), [
            'name' => 'Standard HR Approval',
            'description' => 'Two-step approval',
            'stages' => [
                [
                    'action' => 'review',
                    'completion_rule' => 'all',
                    'targets' => [[
                        'target_type' => 'department_manager',
                    ]],
                ],
                [
                    'action' => 'approve',
                    'completion_rule' => 'any',
                    'targets' => [[
                        'target_type' => 'specific_user',
                        'target_user_id' => $approver->id,
                    ]],
                ],
            ],
        ])
        ->assertRedirect();

    $preset = DocumentWorkflowPreset::query()->first();

    expect($preset)->not->toBeNull()
        ->and($preset->name)->toBe('Standard HR Approval')
        ->and($preset->status)->toBe(DocumentWorkflowPresetStatus::Active);

    $preset->load('stages.targets');

    expect($preset->stages)->toHaveCount(2)
        ->and($preset->stages->first()->targets)->toHaveCount(1);

    expect(Activity::query()->where('event', 'workflow_preset_created')->exists())->toBeTrue();
});

test('rejects preset when final stage is not approve', function () {
    $company = makeDocumentFixtures()['company'];
    $admin = User::factory()->create();
    grantPresetPermissions($admin, $company, ['documents.workflow-presets.create']);

    $this->actingAs($admin)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.workflow-presets.store'), [
            'name' => 'Invalid preset',
            'stages' => [[
                'action' => 'review',
                'completion_rule' => 'all',
                'targets' => [[
                    'target_type' => 'department_manager',
                ]],
            ]],
        ])
        ->assertSessionHasErrors('stages');
});

test('rejects duplicate targets within the same stage', function () {
    $company = makeDocumentFixtures()['company'];
    $admin = User::factory()->create();
    grantPresetPermissions($admin, $company, ['documents.workflow-presets.create']);

    $this->actingAs($admin)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.workflow-presets.store'), [
            'name' => 'Duplicate targets',
            'stages' => [[
                'action' => 'approve',
                'completion_rule' => 'any',
                'targets' => [
                    ['target_type' => 'department_manager'],
                    ['target_type' => 'department_manager'],
                ],
            ]],
        ])
        ->assertSessionHasErrors('stages.0.targets');
});

test('rejects cross-company specific user target', function () {
    ['company' => $company] = makeGeneratedDocumentWorkflowFixtures();
    $otherCompany = makeDocumentFixtures()['company'];
    $admin = User::factory()->create();
    $foreignUser = User::factory()->create();

    addCompanyMembership($foreignUser, $otherCompany);
    grantPresetPermissions($admin, $company, ['documents.workflow-presets.create']);

    $this->actingAs($admin)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.workflow-presets.store'), [
            'name' => 'Cross company user',
            'stages' => [[
                'action' => 'approve',
                'completion_rule' => 'any',
                'targets' => [[
                    'target_type' => 'specific_user',
                    'target_user_id' => $foreignUser->id,
                ]],
            ]],
        ])
        ->assertSessionHasErrors('stages.0.targets.0.target_user_id');
});

test('rejects cross-company role target', function () {
    ['company' => $company] = makeGeneratedDocumentWorkflowFixtures();
    $otherCompany = makeDocumentFixtures()['company'];
    $admin = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($otherCompany->id);
    $foreignRole = Role::query()->create([
        'company_id' => $otherCompany->id,
        'name' => 'Foreign GM',
        'guard_name' => 'web',
    ]);

    grantPresetPermissions($admin, $company, ['documents.workflow-presets.create']);

    $this->actingAs($admin)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.workflow-presets.store'), [
            'name' => 'Cross company role',
            'stages' => [[
                'action' => 'approve',
                'completion_rule' => 'any',
                'targets' => [[
                    'target_type' => 'company_role',
                    'target_role_id' => $foreignRole->id,
                ]],
            ]],
        ])
        ->assertSessionHasErrors('stages.0.targets.0.target_role_id');
});

test('used preset cannot be deleted but unused preset can', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document] = makeGeneratedDocumentWorkflowFixtures();

    $admin = User::factory()->create();
    $reviewer = User::factory()->create();
    $approver = User::factory()->create();

    grantPresetPermissions($admin, $company, [
        'documents.workflow-presets.create',
        'documents.workflow-presets.delete',
        'documents.workflow-presets.view',
    ]);
    giveCompanyPermission($reviewer, $company, 'documents.requests.review');
    giveCompanyPermission($approver, $company, 'documents.requests.approve');
    giveCompanyPermission($admin, $company, 'documents.requests.create');

    $preset = app(StoreDocumentWorkflowPreset::class)->handle(
        actor: $admin,
        companyId: $company->id,
        name: 'Used preset',
        description: null,
        stages: [
            [
                'action' => 'review',
                'completion_rule' => 'all',
                'targets' => [[
                    'target_type' => 'specific_user',
                    'target_user_id' => $reviewer->id,
                ]],
            ],
            [
                'action' => 'approve',
                'completion_rule' => 'any',
                'targets' => [[
                    'target_type' => 'specific_user',
                    'target_user_id' => $approver->id,
                ]],
            ],
        ],
    );

    $this->actingAs($admin)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.employee.files.workflow-requests.store', [
            'employee' => $employee->id,
            'document' => $document->id,
        ]), [
            'workflow_preset_id' => $preset->id,
        ])
        ->assertRedirect();

    $this->actingAs($admin)
        ->withSession(['current_company_id' => $company->id])
        ->delete(route('organization.documents.workflow-presets.destroy', $preset))
        ->assertSessionHasErrors('preset');

    expect(DocumentWorkflowPreset::query()->whereKey($preset->id)->exists())->toBeTrue();

    $unused = app(StoreDocumentWorkflowPreset::class)->handle(
        actor: $admin,
        companyId: $company->id,
        name: 'Unused preset',
        description: null,
        stages: [
            [
                'action' => 'approve',
                'completion_rule' => 'any',
                'targets' => [[
                    'target_type' => 'specific_user',
                    'target_user_id' => $approver->id,
                ]],
            ],
        ],
    );

    $this->actingAs($admin)
        ->withSession(['current_company_id' => $company->id])
        ->delete(route('organization.documents.workflow-presets.destroy', $unused))
        ->assertRedirect();

    expect(DocumentWorkflowPreset::query()->whereKey($unused->id)->exists())->toBeFalse();
});

test('creates workflow from preset resolving department manager for subject employee', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document] = makeGeneratedDocumentWorkflowFixtures();

    $deptManagerEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $deptManagerUser = User::factory()->create(['status' => 'active']);
    $deptManagerEmployee->update(['user_id' => $deptManagerUser->id]);

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Crew',
        'code' => 'CREW',
        'manager_id' => $deptManagerEmployee->id,
        'status' => 'active',
    ]);

    $employee->update(['department_id' => $department->id]);

    giveCompanyPermission($deptManagerUser, $company, 'documents.requests.review');
    giveCompanyPermission($deptManagerUser, $company, 'documents.requests.approve');

    $requester = User::factory()->create();
    giveCompanyPermission($requester, $company, 'documents.requests.create');

    $admin = User::factory()->create();
    grantPresetPermissions($admin, $company, ['documents.workflow-presets.create']);

    $preset = app(StoreDocumentWorkflowPreset::class)->handle(
        actor: $admin,
        companyId: $company->id,
        name: 'Department Approval',
        description: null,
        stages: [
            [
                'action' => 'review',
                'completion_rule' => 'all',
                'targets' => [[
                    'target_type' => 'department_manager',
                ]],
            ],
            [
                'action' => 'approve',
                'completion_rule' => 'all',
                'targets' => [[
                    'target_type' => 'specific_user',
                    'target_user_id' => $deptManagerUser->id,
                ]],
            ],
        ],
    );

    $this->actingAs($requester)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.employee.files.workflow-requests.store', [
            'employee' => $employee->id,
            'document' => $document->id,
        ]), [
            'workflow_preset_id' => $preset->id,
        ])
        ->assertRedirect();

    $workflow = DocumentWorkflowRequest::query()->with('stages.tasks')->first();

    expect($workflow)->not->toBeNull()
        ->and($workflow->document_workflow_preset_id)->toBe($preset->id)
        ->and($workflow->preset_name_snapshot)->toBe('Department Approval')
        ->and($workflow->routing_definition_snapshot)->toBeArray();

    $firstStageTask = $workflow->stages->first()?->tasks->first();

    expect($firstStageTask?->assignee_user_id)->toBe($deptManagerUser->id);
});

test('resolves parent manager as next distinct actionable manager', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document] = makeGeneratedDocumentWorkflowFixtures();

    $parentManagerEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $parentManagerUser = User::factory()->create(['status' => 'active']);
    $parentManagerEmployee->update(['user_id' => $parentManagerUser->id]);

    $crewManagerEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $crewManagerUser = User::factory()->create(['status' => 'active']);
    $crewManagerEmployee->update(['user_id' => $crewManagerUser->id]);

    $operations = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Operations',
        'code' => 'OPS',
        'manager_id' => $parentManagerEmployee->id,
        'status' => 'active',
    ]);

    $crew = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Crew',
        'code' => 'CREW',
        'parent_id' => $operations->id,
        'manager_id' => $crewManagerEmployee->id,
        'status' => 'active',
    ]);

    $employee->update(['department_id' => $crew->id]);

    giveCompanyPermission($crewManagerUser, $company, 'documents.requests.review');
    giveCompanyPermission($crewManagerUser, $company, 'documents.requests.approve');
    giveCompanyPermission($parentManagerUser, $company, 'documents.requests.approve');

    $requester = User::factory()->create();
    giveCompanyPermission($requester, $company, 'documents.requests.create');

    $admin = User::factory()->create();
    grantPresetPermissions($admin, $company, ['documents.workflow-presets.create']);

    $preset = app(StoreDocumentWorkflowPreset::class)->handle(
        actor: $admin,
        companyId: $company->id,
        name: 'Parent manager preset',
        description: null,
        stages: [
            [
                'action' => 'review',
                'completion_rule' => 'all',
                'targets' => [[
                    'target_type' => 'department_manager',
                ]],
            ],
            [
                'action' => 'approve',
                'completion_rule' => 'any',
                'targets' => [[
                    'target_type' => 'parent_manager',
                ]],
            ],
        ],
    );

    $response = $this->actingAs($requester)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.employee.files.workflow-requests.store', [
            'employee' => $employee->id,
            'document' => $document->id,
        ]), [
            'workflow_preset_id' => $preset->id,
        ]);

    $response->assertRedirect()->assertSessionHasNoErrors();

    $workflow = DocumentWorkflowRequest::query()->with('stages.tasks')->first();

    expect($workflow)->not->toBeNull();
    $approveTask = $workflow->stages->last()?->tasks->first();

    expect($approveTask?->assignee_user_id)->toBe($parentManagerUser->id);
});

test('blocks preset workflow when department manager cannot be resolved', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document] = makeGeneratedDocumentWorkflowFixtures();

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'No Manager Dept',
        'code' => 'NMD',
        'status' => 'active',
    ]);

    $employee->update(['department_id' => $department->id]);

    $requester = User::factory()->create();
    giveCompanyPermission($requester, $company, 'documents.requests.create');

    $admin = User::factory()->create();
    grantPresetPermissions($admin, $company, ['documents.workflow-presets.create']);

    $preset = app(StoreDocumentWorkflowPreset::class)->handle(
        actor: $admin,
        companyId: $company->id,
        name: 'Needs manager',
        description: null,
        stages: [[
            'action' => 'approve',
            'completion_rule' => 'any',
            'targets' => [[
                'target_type' => 'department_manager',
            ]],
        ]],
    );

    $this->actingAs($requester)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.employee.files.workflow-requests.store', [
            'employee' => $employee->id,
            'document' => $document->id,
        ]), [
            'workflow_preset_id' => $preset->id,
        ])
        ->assertSessionHasErrors('workflow_preset_id');
});

test('company role target resolves eligible members and excludes requester', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document] = makeGeneratedDocumentWorkflowFixtures();

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $role = Role::query()->create([
        'company_id' => $company->id,
        'name' => 'General Manager',
        'guard_name' => 'web',
    ]);

    $memberOne = User::factory()->create(['status' => 'active']);
    $memberTwo = User::factory()->create(['status' => 'active']);
    $requester = User::factory()->create(['status' => 'active']);

    addCompanyMembership($memberOne, $company);
    addCompanyMembership($memberTwo, $company);
    addCompanyMembership($requester, $company);

    giveCompanyPermission($memberOne, $company, 'documents.requests.approve');
    giveCompanyPermission($memberTwo, $company, 'documents.requests.approve');
    giveCompanyPermission($requester, $company, 'documents.requests.create');
    giveCompanyPermission($requester, $company, 'documents.requests.approve');

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $memberOne->assignRole($role);
    $memberTwo->assignRole($role);
    $requester->assignRole($role);

    $admin = User::factory()->create();
    grantPresetPermissions($admin, $company, ['documents.workflow-presets.create']);

    $preset = app(StoreDocumentWorkflowPreset::class)->handle(
        actor: $admin,
        companyId: $company->id,
        name: 'GM role',
        description: null,
        stages: [[
            'action' => 'approve',
            'completion_rule' => 'all',
            'targets' => [[
                'target_type' => 'company_role',
                'target_role_id' => $role->id,
            ]],
        ]],
    );

    $this->actingAs($requester)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.employee.files.workflow-requests.store', [
            'employee' => $employee->id,
            'document' => $document->id,
        ]), [
            'workflow_preset_id' => $preset->id,
        ])
        ->assertRedirect();

    $workflow = DocumentWorkflowRequest::query()->with('stages.tasks')->first();
    $assigneeIds = $workflow->stages->first()?->tasks->pluck('assignee_user_id')->sort()->values()->all();

    expect($assigneeIds)->toBe(collect([$memberOne->id, $memberTwo->id])->sort()->values()->all())
        ->and($assigneeIds)->not->toContain($requester->id);
});

test('deduplicates same user from multiple targets within one stage', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document] = makeGeneratedDocumentWorkflowFixtures();

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $role = Role::query()->create([
        'company_id' => $company->id,
        'name' => 'HR Officer',
        'guard_name' => 'web',
    ]);

    $sharedUser = User::factory()->create(['status' => 'active']);
    addCompanyMembership($sharedUser, $company);
    giveCompanyPermission($sharedUser, $company, 'documents.requests.review');
    giveCompanyPermission($sharedUser, $company, 'documents.requests.approve');

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $sharedUser->assignRole($role);

    $requester = User::factory()->create();
    giveCompanyPermission($requester, $company, 'documents.requests.create');

    $admin = User::factory()->create();
    grantPresetPermissions($admin, $company, ['documents.workflow-presets.create']);

    $preset = app(StoreDocumentWorkflowPreset::class)->handle(
        actor: $admin,
        companyId: $company->id,
        name: 'Dedup preset',
        description: null,
        stages: [
            [
                'action' => 'review',
                'completion_rule' => 'all',
                'targets' => [
                    [
                        'target_type' => 'specific_user',
                        'target_user_id' => $sharedUser->id,
                    ],
                    [
                        'target_type' => 'company_role',
                        'target_role_id' => $role->id,
                    ],
                ],
            ],
            [
                'action' => 'approve',
                'completion_rule' => 'any',
                'targets' => [[
                    'target_type' => 'specific_user',
                    'target_user_id' => $sharedUser->id,
                ]],
            ],
        ],
    );

    $this->actingAs($requester)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.employee.files.workflow-requests.store', [
            'employee' => $employee->id,
            'document' => $document->id,
        ]), [
            'workflow_preset_id' => $preset->id,
        ])
        ->assertRedirect();

    $workflow = DocumentWorkflowRequest::query()->with('stages.tasks')->first();
    $reviewTasks = $workflow->stages->first()?->tasks;

    expect($reviewTasks)->toHaveCount(1)
        ->and($reviewTasks->first()?->assignee_user_id)->toBe($sharedUser->id);
});

test('manager changes after request creation do not alter existing tasks', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document] = makeGeneratedDocumentWorkflowFixtures();

    $originalManagerEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $originalManagerUser = User::factory()->create(['status' => 'active']);
    $originalManagerEmployee->update(['user_id' => $originalManagerUser->id]);

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Ops',
        'code' => 'OPS',
        'manager_id' => $originalManagerEmployee->id,
        'status' => 'active',
    ]);

    $employee->update(['department_id' => $department->id]);
    giveCompanyPermission($originalManagerUser, $company, 'documents.requests.approve');

    $requester = User::factory()->create();
    giveCompanyPermission($requester, $company, 'documents.requests.create');

    $admin = User::factory()->create();
    grantPresetPermissions($admin, $company, ['documents.workflow-presets.create']);

    $preset = app(StoreDocumentWorkflowPreset::class)->handle(
        actor: $admin,
        companyId: $company->id,
        name: 'Snapshot preset',
        description: null,
        stages: [[
            'action' => 'approve',
            'completion_rule' => 'any',
            'targets' => [[
                'target_type' => 'department_manager',
            ]],
        ]],
    );

    $this->actingAs($requester)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.employee.files.workflow-requests.store', [
            'employee' => $employee->id,
            'document' => $document->id,
        ]), [
            'workflow_preset_id' => $preset->id,
        ])
        ->assertRedirect();

    $taskId = DocumentWorkflowTask::query()->value('assignee_user_id');

    $replacementManager = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $replacementUser = User::factory()->create(['status' => 'active']);
    $replacementManager->update(['user_id' => $replacementUser->id]);
    $department->update(['manager_id' => $replacementManager->id]);
    giveCompanyPermission($replacementUser, $company, 'documents.requests.approve');

    expect(DocumentWorkflowTask::query()->value('assignee_user_id'))->toBe($taskId)
        ->and($taskId)->toBe($originalManagerUser->id);
});

test('manual workflow creation still works without preset', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document] = makeGeneratedDocumentWorkflowFixtures();

    $requester = User::factory()->create();
    $approver = User::factory()->create();

    giveCompanyPermission($requester, $company, 'documents.requests.create');
    giveCompanyPermission($approver, $company, 'documents.requests.approve');

    $this->actingAs($requester)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.employee.files.workflow-requests.store', [
            'employee' => $employee->id,
            'document' => $document->id,
        ]), [
            'stages' => [[
                'action' => 'approve',
                'completion_rule' => 'any',
                'assignee_user_ids' => [$approver->id],
            ]],
        ])
        ->assertRedirect();

    $workflow = DocumentWorkflowRequest::query()->first();

    expect($workflow)->not->toBeNull()
        ->and($workflow->document_workflow_preset_id)->toBeNull()
        ->and($workflow->preset_name_snapshot)->toBeNull();
});

test('preset and manual stages cannot be submitted together', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document] = makeGeneratedDocumentWorkflowFixtures();

    $requester = User::factory()->create();
    $approver = User::factory()->create();

    giveCompanyPermission($requester, $company, 'documents.requests.create');
    giveCompanyPermission($approver, $company, 'documents.requests.approve');

    $admin = User::factory()->create();
    grantPresetPermissions($admin, $company, ['documents.workflow-presets.create']);

    $preset = app(StoreDocumentWorkflowPreset::class)->handle(
        actor: $admin,
        companyId: $company->id,
        name: 'Either or',
        description: null,
        stages: [[
            'action' => 'approve',
            'completion_rule' => 'any',
            'targets' => [[
                'target_type' => 'specific_user',
                'target_user_id' => $approver->id,
            ]],
        ]],
    );

    $this->actingAs($requester)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.employee.files.workflow-requests.store', [
            'employee' => $employee->id,
            'document' => $document->id,
        ]), [
            'workflow_preset_id' => $preset->id,
            'stages' => [[
                'action' => 'approve',
                'completion_rule' => 'any',
                'assignee_user_ids' => [$approver->id],
            ]],
        ])
        ->assertSessionHasErrors('workflow_preset_id');
});

test('cross-company preset id is rejected at validation', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document] = makeGeneratedDocumentWorkflowFixtures();
    $otherCompany = makeDocumentFixtures()['company'];

    $foreignAdmin = User::factory()->create();
    grantPresetPermissions($foreignAdmin, $otherCompany, ['documents.workflow-presets.create']);

    $foreignApprover = User::factory()->create();
    giveCompanyPermission($foreignApprover, $otherCompany, 'documents.requests.approve');

    $foreignPreset = app(StoreDocumentWorkflowPreset::class)->handle(
        actor: $foreignAdmin,
        companyId: $otherCompany->id,
        name: 'Foreign preset',
        description: null,
        stages: [[
            'action' => 'approve',
            'completion_rule' => 'any',
            'targets' => [[
                'target_type' => 'specific_user',
                'target_user_id' => $foreignApprover->id,
            ]],
        ]],
    );

    $requester = User::factory()->create();
    giveCompanyPermission($requester, $company, 'documents.requests.create');

    $this->actingAs($requester)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.employee.files.workflow-requests.store', [
            'employee' => $employee->id,
            'document' => $document->id,
        ]), [
            'workflow_preset_id' => $foreignPreset->id,
        ])
        ->assertSessionHasErrors('workflow_preset_id');
});

test('rejects specific user target that includes a role id', function () {
    $company = makeDocumentFixtures()['company'];
    $admin = User::factory()->create();
    $approver = User::factory()->create();

    grantPresetPermissions($admin, $company, ['documents.workflow-presets.create']);
    giveCompanyPermission($approver, $company, 'documents.requests.approve');

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $role = Role::query()->create([
        'company_id' => $company->id,
        'name' => 'Reviewer',
        'guard_name' => 'web',
    ]);

    $this->actingAs($admin)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.workflow-presets.store'), [
            'name' => 'Invalid specific user target',
            'stages' => [[
                'action' => 'approve',
                'completion_rule' => 'any',
                'targets' => [[
                    'target_type' => 'specific_user',
                    'target_user_id' => $approver->id,
                    'target_role_id' => $role->id,
                ]],
            ]],
        ])
        ->assertSessionHasErrors('stages.0.targets.0.target_role_id');
});

test('rejects company role target that includes a user id', function () {
    $company = makeDocumentFixtures()['company'];
    $admin = User::factory()->create();
    $approver = User::factory()->create();

    grantPresetPermissions($admin, $company, ['documents.workflow-presets.create']);
    giveCompanyPermission($approver, $company, 'documents.requests.approve');

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $role = Role::query()->create([
        'company_id' => $company->id,
        'name' => 'Approver role',
        'guard_name' => 'web',
    ]);

    $this->actingAs($admin)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.workflow-presets.store'), [
            'name' => 'Invalid company role target',
            'stages' => [[
                'action' => 'approve',
                'completion_rule' => 'any',
                'targets' => [[
                    'target_type' => 'company_role',
                    'target_role_id' => $role->id,
                    'target_user_id' => $approver->id,
                ]],
            ]],
        ])
        ->assertSessionHasErrors('stages.0.targets.0.target_user_id');
});

test('rejects manager target that includes user or role selections', function () {
    $company = makeDocumentFixtures()['company'];
    $admin = User::factory()->create();
    $user = User::factory()->create();

    grantPresetPermissions($admin, $company, ['documents.workflow-presets.create']);
    giveCompanyPermission($user, $company, 'documents.requests.approve');

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $role = Role::query()->create([
        'company_id' => $company->id,
        'name' => 'Ops',
        'guard_name' => 'web',
    ]);

    $this->actingAs($admin)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.workflow-presets.store'), [
            'name' => 'Invalid manager target',
            'stages' => [[
                'action' => 'approve',
                'completion_rule' => 'any',
                'targets' => [[
                    'target_type' => 'department_manager',
                    'target_user_id' => $user->id,
                    'target_role_id' => $role->id,
                ]],
            ]],
        ])
        ->assertSessionHasErrors('stages.0.targets.0.target_type');
});

test('persists only valid target fields for each target type', function () {
    $company = makeDocumentFixtures()['company'];
    $admin = User::factory()->create();
    $approver = User::factory()->create();

    grantPresetPermissions($admin, $company, ['documents.workflow-presets.create']);
    giveCompanyPermission($approver, $company, 'documents.requests.approve');

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $role = Role::query()->create([
        'company_id' => $company->id,
        'name' => 'GM',
        'guard_name' => 'web',
    ]);
    $approver->assignRole($role);

    $this->actingAs($admin)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.workflow-presets.store'), [
            'name' => 'Sanitized persistence',
            'stages' => [[
                'action' => 'approve',
                'completion_rule' => 'any',
                'targets' => [[
                    'target_type' => 'company_role',
                    'target_role_id' => $role->id,
                    'target_user_id' => $approver->id,
                ]],
            ]],
        ])
        ->assertSessionHasErrors('stages.0.targets.0.target_user_id');

    $this->actingAs($admin)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.workflow-presets.store'), [
            'name' => 'Clean company role preset',
            'stages' => [[
                'action' => 'approve',
                'completion_rule' => 'any',
                'targets' => [[
                    'target_type' => 'company_role',
                    'target_role_id' => $role->id,
                ]],
            ]],
        ])
        ->assertRedirect();

    $target = DocumentWorkflowPreset::query()->where('name', 'Clean company role preset')->firstOrFail()
        ->stages()->first()->targets()->first();

    expect($target->target_role_id)->toBe($role->id)
        ->and($target->target_user_id)->toBeNull();
});

test('routing snapshot contains only metadata valid for target type', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document] = makeGeneratedDocumentWorkflowFixtures();

    $approver = User::factory()->create(['status' => 'active', 'company_id' => $company->id]);
    giveCompanyPermission($approver, $company, 'documents.requests.approve');

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $role = Role::query()->create([
        'company_id' => $company->id,
        'name' => 'Foreign role label',
        'guard_name' => 'web',
    ]);

    $requester = User::factory()->create();
    giveCompanyPermission($requester, $company, 'documents.requests.create');

    $admin = User::factory()->create();
    grantPresetPermissions($admin, $company, ['documents.workflow-presets.create']);

    $preset = app(StoreDocumentWorkflowPreset::class)->handle(
        actor: $admin,
        companyId: $company->id,
        name: 'Snapshot preset',
        description: null,
        stages: [[
            'action' => 'approve',
            'completion_rule' => 'any',
            'targets' => [[
                'target_type' => 'specific_user',
                'target_user_id' => $approver->id,
            ]],
        ]],
    );

    $target = $preset->stages()->first()->targets()->first();
    $target->forceFill([
        'target_role_id' => $role->id,
    ])->save();

    $this->actingAs($requester)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.employee.files.workflow-requests.store', [
            'employee' => $employee->id,
            'document' => $document->id,
        ]), [
            'workflow_preset_id' => $preset->id,
        ])
        ->assertRedirect();

    $snapshot = DocumentWorkflowRequest::query()->value('routing_definition_snapshot');
    $targetSnapshot = $snapshot[0]['targets'][0];

    expect($targetSnapshot)->toHaveKeys(['target_type', 'target_user_id', 'target_user_name', 'label'])
        ->and($targetSnapshot)->not->toHaveKey('target_role_id')
        ->and($targetSnapshot)->not->toHaveKey('target_role_name');
});

test('legacy home-company specific user resolves for workflow request', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document] = makeGeneratedDocumentWorkflowFixtures();

    $legacyApprover = User::factory()->create([
        'status' => 'active',
        'company_id' => $company->id,
    ]);
    grantLegacyHomeCompanyPermission($legacyApprover, $company, 'documents.requests.approve');

    $requester = User::factory()->create();
    giveCompanyPermission($requester, $company, 'documents.requests.create');

    $admin = User::factory()->create();
    grantPresetPermissions($admin, $company, ['documents.workflow-presets.create']);

    $preset = app(StoreDocumentWorkflowPreset::class)->handle(
        actor: $admin,
        companyId: $company->id,
        name: 'Legacy specific user',
        description: null,
        stages: [[
            'action' => 'approve',
            'completion_rule' => 'any',
            'targets' => [[
                'target_type' => 'specific_user',
                'target_user_id' => $legacyApprover->id,
            ]],
        ]],
    );

    $this->actingAs($requester)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.employee.files.workflow-requests.store', [
            'employee' => $employee->id,
            'document' => $document->id,
        ]), [
            'workflow_preset_id' => $preset->id,
        ])
        ->assertRedirect();

    expect(DocumentWorkflowTask::query()->value('assignee_user_id'))->toBe($legacyApprover->id);
});

test('legacy home-company role member resolves for company role target', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document] = makeGeneratedDocumentWorkflowFixtures();

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $role = Role::query()->create([
        'company_id' => $company->id,
        'name' => 'Legacy GM',
        'guard_name' => 'web',
    ]);

    $legacyMember = User::factory()->create([
        'status' => 'active',
        'company_id' => $company->id,
    ]);
    grantLegacyHomeCompanyPermission($legacyMember, $company, 'documents.requests.approve');
    $legacyMember->assignRole($role);

    $requester = User::factory()->create();
    giveCompanyPermission($requester, $company, 'documents.requests.create');

    $admin = User::factory()->create();
    grantPresetPermissions($admin, $company, ['documents.workflow-presets.create']);

    $preset = app(StoreDocumentWorkflowPreset::class)->handle(
        actor: $admin,
        companyId: $company->id,
        name: 'Legacy role preset',
        description: null,
        stages: [[
            'action' => 'approve',
            'completion_rule' => 'all',
            'targets' => [[
                'target_type' => 'company_role',
                'target_role_id' => $role->id,
            ]],
        ]],
    );

    $this->actingAs($requester)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.employee.files.workflow-requests.store', [
            'employee' => $employee->id,
            'document' => $document->id,
        ]), [
            'workflow_preset_id' => $preset->id,
        ])
        ->assertRedirect();

    expect(DocumentWorkflowTask::query()->pluck('assignee_user_id')->all())->toBe([$legacyMember->id]);
});

test('legacy manager-linked user resolves for department manager target', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document] = makeGeneratedDocumentWorkflowFixtures();

    $legacyManager = User::factory()->create([
        'status' => 'active',
        'company_id' => $company->id,
    ]);
    grantLegacyHomeCompanyPermission($legacyManager, $company, 'documents.requests.approve');

    $managerEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'user_id' => $legacyManager->id,
    ]);

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Legacy Dept',
        'code' => 'LD',
        'manager_id' => $managerEmployee->id,
        'status' => 'active',
    ]);
    $employee->update(['department_id' => $department->id]);

    $requester = User::factory()->create();
    giveCompanyPermission($requester, $company, 'documents.requests.create');

    $admin = User::factory()->create();
    grantPresetPermissions($admin, $company, ['documents.workflow-presets.create']);

    $preset = app(StoreDocumentWorkflowPreset::class)->handle(
        actor: $admin,
        companyId: $company->id,
        name: 'Legacy manager preset',
        description: null,
        stages: [[
            'action' => 'approve',
            'completion_rule' => 'any',
            'targets' => [[
                'target_type' => 'department_manager',
            ]],
        ]],
    );

    $this->actingAs($requester)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.employee.files.workflow-requests.store', [
            'employee' => $employee->id,
            'document' => $document->id,
        ]), [
            'workflow_preset_id' => $preset->id,
        ])
        ->assertRedirect();

    expect(DocumentWorkflowTask::query()->value('assignee_user_id'))->toBe($legacyManager->id);
});

test('inactive pivot membership blocks company role resolution', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document] = makeGeneratedDocumentWorkflowFixtures();

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $role = Role::query()->create([
        'company_id' => $company->id,
        'name' => 'Blocked role',
        'guard_name' => 'web',
    ]);

    $inactiveMember = User::factory()->create(['status' => 'active']);
    addCompanyMembership($inactiveMember, $company);
    giveCompanyPermission($inactiveMember, $company, 'documents.requests.approve');
    $inactiveMember->assignRole($role);

    DB::table('company_user')
        ->where('company_id', $company->id)
        ->where('user_id', $inactiveMember->id)
        ->update(['status' => 'inactive']);

    $requester = User::factory()->create();
    giveCompanyPermission($requester, $company, 'documents.requests.create');

    $admin = User::factory()->create();
    grantPresetPermissions($admin, $company, ['documents.workflow-presets.create']);

    $preset = app(StoreDocumentWorkflowPreset::class)->handle(
        actor: $admin,
        companyId: $company->id,
        name: 'Inactive member role',
        description: null,
        stages: [[
            'action' => 'approve',
            'completion_rule' => 'any',
            'targets' => [[
                'target_type' => 'company_role',
                'target_role_id' => $role->id,
            ]],
        ]],
    );

    $this->actingAs($requester)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.employee.files.workflow-requests.store', [
            'employee' => $employee->id,
            'document' => $document->id,
        ]), [
            'workflow_preset_id' => $preset->id,
        ])
        ->assertSessionHasErrors('workflow_preset_id');
});

test('rejects duplicate preset name within the same company', function () {
    $company = makeDocumentFixtures()['company'];
    $admin = User::factory()->create();
    $approver = User::factory()->create();

    grantPresetPermissions($admin, $company, ['documents.workflow-presets.create']);
    giveCompanyPermission($approver, $company, 'documents.requests.approve');

    $payload = [
        'name' => 'Standard Approval',
        'stages' => [[
            'action' => 'approve',
            'completion_rule' => 'any',
            'targets' => [[
                'target_type' => 'specific_user',
                'target_user_id' => $approver->id,
            ]],
        ]],
    ];

    $this->actingAs($admin)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.workflow-presets.store'), $payload)
        ->assertRedirect();

    $this->actingAs($admin)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.workflow-presets.store'), $payload)
        ->assertSessionHasErrors('name');
});

test('allows same preset name in different companies', function () {
    $companyA = makeDocumentFixtures()['company'];
    $companyB = makeDocumentFixtures()['company'];

    $adminA = User::factory()->create();
    $adminB = User::factory()->create();
    $approverA = User::factory()->create();
    $approverB = User::factory()->create();

    grantPresetPermissions($adminA, $companyA, ['documents.workflow-presets.create']);
    grantPresetPermissions($adminB, $companyB, ['documents.workflow-presets.create']);
    giveCompanyPermission($approverA, $companyA, 'documents.requests.approve');
    giveCompanyPermission($approverB, $companyB, 'documents.requests.approve');

    $payload = fn (User $approver) => [
        'name' => 'Standard Approval',
        'stages' => [[
            'action' => 'approve',
            'completion_rule' => 'any',
            'targets' => [[
                'target_type' => 'specific_user',
                'target_user_id' => $approver->id,
            ]],
        ]],
    ];

    $this->actingAs($adminA)
        ->withSession(['current_company_id' => $companyA->id])
        ->post(route('organization.documents.workflow-presets.store'), $payload($approverA))
        ->assertRedirect();

    $this->actingAs($adminB)
        ->withSession(['current_company_id' => $companyB->id])
        ->post(route('organization.documents.workflow-presets.store'), $payload($approverB))
        ->assertRedirect();

    expect(DocumentWorkflowPreset::query()->where('name', 'Standard Approval')->count())->toBe(2);
});

test('allows updating preset without changing its name', function () {
    $company = makeDocumentFixtures()['company'];
    $admin = User::factory()->create();
    $approver = User::factory()->create();

    grantPresetPermissions($admin, $company, [
        'documents.workflow-presets.create',
        'documents.workflow-presets.update',
    ]);
    giveCompanyPermission($approver, $company, 'documents.requests.approve');

    $this->actingAs($admin)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.workflow-presets.store'), [
            'name' => 'Standard Approval',
            'stages' => [[
                'action' => 'approve',
                'completion_rule' => 'any',
                'targets' => [[
                    'target_type' => 'specific_user',
                    'target_user_id' => $approver->id,
                ]],
            ]],
        ])
        ->assertRedirect();

    $preset = DocumentWorkflowPreset::query()->firstOrFail();

    $this->actingAs($admin)
        ->withSession(['current_company_id' => $company->id])
        ->put(route('organization.documents.workflow-presets.update', $preset), [
            'name' => 'Standard Approval',
            'description' => 'Updated description',
            'stages' => [[
                'action' => 'approve',
                'completion_rule' => 'any',
                'targets' => [[
                    'target_type' => 'specific_user',
                    'target_user_id' => $approver->id,
                ]],
            ]],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($preset->fresh()->description)->toBe('Updated description');
});

test('inactive preset cannot be used for workflow request creation', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document] = makeGeneratedDocumentWorkflowFixtures();

    $approver = User::factory()->create();
    giveCompanyPermission($approver, $company, 'documents.requests.approve');

    $requester = User::factory()->create();
    giveCompanyPermission($requester, $company, 'documents.requests.create');

    $admin = User::factory()->create();
    grantPresetPermissions($admin, $company, [
        'documents.workflow-presets.create',
        'documents.workflow-presets.update',
    ]);

    $preset = app(StoreDocumentWorkflowPreset::class)->handle(
        actor: $admin,
        companyId: $company->id,
        name: 'Inactive preset',
        description: null,
        stages: [[
            'action' => 'approve',
            'completion_rule' => 'any',
            'targets' => [[
                'target_type' => 'specific_user',
                'target_user_id' => $approver->id,
            ]],
        ]],
    );

    app(DeactivateDocumentWorkflowPreset::class)
        ->handle($preset, $admin, $company->id);

    $this->actingAs($requester)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.employee.files.workflow-requests.store', [
            'employee' => $employee->id,
            'document' => $document->id,
        ]), [
            'workflow_preset_id' => $preset->id,
        ])
        ->assertSessionHasErrors('workflow_preset_id');
});

test('preset update and deactivation do not alter existing request tasks', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document] = makeGeneratedDocumentWorkflowFixtures();

    $originalApprover = User::factory()->create(['status' => 'active']);
    $replacementApprover = User::factory()->create(['status' => 'active']);
    giveCompanyPermission($originalApprover, $company, 'documents.requests.approve');
    giveCompanyPermission($replacementApprover, $company, 'documents.requests.approve');

    $requester = User::factory()->create();
    giveCompanyPermission($requester, $company, 'documents.requests.create');

    $admin = User::factory()->create();
    grantPresetPermissions($admin, $company, [
        'documents.workflow-presets.create',
        'documents.workflow-presets.update',
    ]);

    $preset = app(StoreDocumentWorkflowPreset::class)->handle(
        actor: $admin,
        companyId: $company->id,
        name: 'Mutable preset',
        description: null,
        stages: [[
            'action' => 'approve',
            'completion_rule' => 'any',
            'targets' => [[
                'target_type' => 'specific_user',
                'target_user_id' => $originalApprover->id,
            ]],
        ]],
    );

    $this->actingAs($requester)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.employee.files.workflow-requests.store', [
            'employee' => $employee->id,
            'document' => $document->id,
        ]), [
            'workflow_preset_id' => $preset->id,
        ])
        ->assertRedirect();

    $originalTaskAssignee = DocumentWorkflowTask::query()->value('assignee_user_id');
    $originalSnapshot = DocumentWorkflowRequest::query()->value('routing_definition_snapshot');

    app(UpdateDocumentWorkflowPreset::class)->handle(
        preset: $preset,
        actor: $admin,
        companyId: $company->id,
        name: 'Mutable preset',
        description: 'Changed',
        stages: [[
            'action' => 'approve',
            'completion_rule' => 'any',
            'targets' => [[
                'target_type' => 'specific_user',
                'target_user_id' => $replacementApprover->id,
            ]],
        ]],
    );

    app(DeactivateDocumentWorkflowPreset::class)
        ->handle($preset->fresh(), $admin, $company->id);

    expect(DocumentWorkflowTask::query()->value('assignee_user_id'))->toBe($originalTaskAssignee)
        ->and(DocumentWorkflowRequest::query()->value('routing_definition_snapshot'))->toBe($originalSnapshot);
});

test('new requests use the current locked preset definition after update', function () {
    ['company' => $company, 'employee' => $employee, 'document' => $document, 'instance' => $instance, 'version' => $version] = makeGeneratedDocumentWorkflowFixtures();

    $originalApprover = User::factory()->create(['status' => 'active']);
    $replacementApprover = User::factory()->create(['status' => 'active']);
    giveCompanyPermission($originalApprover, $company, 'documents.requests.approve');
    giveCompanyPermission($replacementApprover, $company, 'documents.requests.approve');

    $requester = User::factory()->create();
    giveCompanyPermission($requester, $company, 'documents.requests.create');

    $admin = User::factory()->create();
    grantPresetPermissions($admin, $company, [
        'documents.workflow-presets.create',
        'documents.workflow-presets.update',
    ]);

    $preset = app(StoreDocumentWorkflowPreset::class)->handle(
        actor: $admin,
        companyId: $company->id,
        name: 'Evolving preset',
        description: null,
        stages: [[
            'action' => 'approve',
            'completion_rule' => 'any',
            'targets' => [[
                'target_type' => 'specific_user',
                'target_user_id' => $originalApprover->id,
            ]],
        ]],
    );

    $this->actingAs($requester)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.employee.files.workflow-requests.store', [
            'employee' => $employee->id,
            'document' => $document->id,
        ]), [
            'workflow_preset_id' => $preset->id,
        ])
        ->assertRedirect();

    DocumentWorkflowRequest::query()->first()->update([
        'status' => DocumentWorkflowRequestStatus::Approved,
    ]);

    app(UpdateDocumentWorkflowPreset::class)->handle(
        preset: $preset,
        actor: $admin,
        companyId: $company->id,
        name: 'Evolving preset',
        description: null,
        stages: [[
            'action' => 'approve',
            'completion_rule' => 'any',
            'targets' => [[
                'target_type' => 'specific_user',
                'target_user_id' => $replacementApprover->id,
            ]],
        ]],
    );

    $version2Path = "document-instances/{$company->id}/canonical-v2.pdf";
    Storage::disk('local')->put($version2Path, '%PDF-1.4 test v2');

    $version2 = DocumentInstanceVersion::query()->create([
        'company_id' => $company->id,
        'document_instance_id' => $instance->id,
        'version' => 2,
        'file_path' => $version2Path,
        'original_filename' => 'canonical-v2.pdf',
        'size_bytes' => 100,
        'checksum' => 'def',
    ]);
    $instance->update(['current_version_id' => $version2->id]);

    $this->actingAs($requester)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.documents.employee.files.workflow-requests.store', [
            'employee' => $employee->id,
            'document' => $document->id,
        ]), [
            'workflow_preset_id' => $preset->id,
        ])
        ->assertRedirect();

    $latestTaskAssignee = DocumentWorkflowRequest::query()
        ->orderByDesc('id')
        ->first()
        ->stages()
        ->first()
        ->tasks()
        ->value('assignee_user_id');

    expect($latestTaskAssignee)->toBe($replacementApprover->id);
});
