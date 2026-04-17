<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\OnboardingTemplate;
use Illuminate\Database\Seeder;

class OnboardingTemplatesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $companies = Company::query()->get(['id']);

        foreach ($companies as $company) {
            $officeTasks = [
                'version' => 1,
                'stages' => [
                    [
                        'key' => 'draft',
                        'label' => 'Draft',
                        'modules' => ['profile'],
                    ],
                    [
                        'key' => 'documents',
                        'label' => 'Documents',
                        'modules' => ['documents'],
                    ],
                    [
                        'key' => 'contract',
                        'label' => 'Contract',
                        'modules' => ['contract'],
                    ],
                    [
                        'key' => 'done',
                        'label' => 'Done',
                        'modules' => [],
                    ],
                ],
                'modules' => [
                    'profile' => [
                        'label' => 'Profile',
                        'store' => [
                            'table' => 'employees',
                        ],
                        'required_fields' => [
                            'employee_no',
                            'first_name',
                            'last_name',
                            'work_email',
                            'phone',
                            'nationality_id',
                            'branch_id',
                            'department_id',
                            'position_id',
                        ],
                    ],
                    'documents' => [
                        'label' => 'Documents',
                        'store' => [
                            'table' => 'employee_documents',
                        ],
                        'required_docs' => [
                            ['type' => 'passport_copy', 'min' => 1],
                            ['type' => 'photo', 'min' => 1],
                        ],
                    ],
                    'contract' => [
                        'label' => 'Contract',
                        'store' => [
                            'table' => 'employee_contracts',
                            'scope' => 'current_active',
                        ],
                        'required_fields' => [
                            'contract_type',
                            'start_date',
                            'basic_salary',
                        ],
                    ],
                ],
            ];

            $offsiteTasks = [
                'version' => 1,
                'stages' => [
                    [
                        'key' => 'draft',
                        'label' => 'Draft',
                        'modules' => ['profile'],
                    ],
                    [
                        'key' => 'contract',
                        'label' => 'Contract',
                        'modules' => ['contract'],
                    ],
                    [
                        'key' => 'documents',
                        'label' => 'Documents',
                        'modules' => ['documents'],
                    ],
                    [
                        'key' => 'done',
                        'label' => 'Done',
                        'modules' => [],
                    ],
                ],
                'modules' => [
                    ...$officeTasks['modules'],
                ],
            ];

            OnboardingTemplate::query()->updateOrCreate(
                [
                    'company_id' => $company->id,
                    'name' => 'Office Default',
                ],
                [
                    'description' => 'Default onboarding flow for office employees.',
                    'tasks' => $officeTasks,
                    'is_default' => true,
                ]
            );

            OnboardingTemplate::query()->updateOrCreate(
                [
                    'company_id' => $company->id,
                    'name' => 'Offsite Default',
                ],
                [
                    'description' => 'Default onboarding flow for offsite employees.',
                    'tasks' => $offsiteTasks,
                    'is_default' => false,
                ]
            );
        }
    }
}
