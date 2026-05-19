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
                'version' => 2,
                'stages' => [
                    [
                        'key' => 'draft',
                        'label' => 'Draft',
                        'employee_fields' => [
                            ['key' => 'employee_no', 'required' => true],
                            ['key' => 'name', 'required' => true],
                            ['key' => 'work_email', 'required' => true],
                            ['key' => 'phone', 'required' => true],
                            ['key' => 'nationality_id', 'required' => true],
                            ['key' => 'branch_id', 'required' => true],
                            ['key' => 'department_id', 'required' => true],
                            ['key' => 'position_id', 'required' => true],
                        ],
                        'bank_account_fields' => [],
                        'contract_fields' => [],
                        'sea_service_fields' => [],
                        'vaccination_fields' => [],
                        'documents' => [],
                    ],
                    [
                        'key' => 'documents',
                        'label' => 'Documents',
                        'employee_fields' => [],
                        'bank_account_fields' => [],
                        'contract_fields' => [],
                        'sea_service_fields' => [],
                        'vaccination_fields' => [],
                        'documents' => [
                            [
                                'type' => 'passport_copy',
                                'min' => 1,
                                'ask_issue_date' => false,
                                'ask_expiry_date' => false,
                                'ask_document_number' => false,
                            ],
                            [
                                'type' => 'photo',
                                'min' => 1,
                                'ask_issue_date' => false,
                                'ask_expiry_date' => false,
                                'ask_document_number' => false,
                            ],
                        ],
                    ],
                    [
                        'key' => 'contract',
                        'label' => 'Contract',
                        'employee_fields' => [],
                        'bank_account_fields' => [],
                        'contract_fields' => [
                            ['key' => 'contract_type', 'required' => true],
                            ['key' => 'start_date', 'required' => true],
                            ['key' => 'basic_salary', 'required' => true],
                        ],
                        'sea_service_fields' => [],
                        'vaccination_fields' => [],
                        'documents' => [],
                    ],
                    [
                        'key' => 'done',
                        'label' => 'Done',
                        'employee_fields' => [],
                        'bank_account_fields' => [],
                        'contract_fields' => [],
                        'sea_service_fields' => [],
                        'vaccination_fields' => [],
                        'documents' => [],
                    ],
                ],
            ];

            $offsiteTasks = [
                'version' => 2,
                'stages' => [
                    [
                        'key' => 'draft',
                        'label' => 'Draft',
                        'employee_fields' => [
                            ['key' => 'employee_no', 'required' => true],
                            ['key' => 'name', 'required' => true],
                            ['key' => 'work_email', 'required' => true],
                            ['key' => 'phone', 'required' => true],
                            ['key' => 'nationality_id', 'required' => true],
                            ['key' => 'branch_id', 'required' => true],
                            ['key' => 'department_id', 'required' => true],
                            ['key' => 'position_id', 'required' => true],
                        ],
                        'bank_account_fields' => [],
                        'contract_fields' => [],
                        'sea_service_fields' => [],
                        'vaccination_fields' => [],
                        'documents' => [],
                    ],
                    [
                        'key' => 'contract',
                        'label' => 'Contract',
                        'employee_fields' => [],
                        'bank_account_fields' => [],
                        'contract_fields' => [
                            ['key' => 'contract_type', 'required' => true],
                            ['key' => 'start_date', 'required' => true],
                            ['key' => 'basic_salary', 'required' => true],
                        ],
                        'sea_service_fields' => [],
                        'vaccination_fields' => [],
                        'documents' => [],
                    ],
                    [
                        'key' => 'documents',
                        'label' => 'Documents',
                        'employee_fields' => [],
                        'bank_account_fields' => [],
                        'contract_fields' => [],
                        'sea_service_fields' => [],
                        'vaccination_fields' => [],
                        'documents' => [
                            [
                                'type' => 'passport_copy',
                                'min' => 1,
                                'ask_issue_date' => false,
                                'ask_expiry_date' => false,
                                'ask_document_number' => false,
                            ],
                            [
                                'type' => 'photo',
                                'min' => 1,
                                'ask_issue_date' => false,
                                'ask_expiry_date' => false,
                                'ask_document_number' => false,
                            ],
                        ],
                    ],
                    [
                        'key' => 'done',
                        'label' => 'Done',
                        'employee_fields' => [],
                        'bank_account_fields' => [],
                        'contract_fields' => [],
                        'sea_service_fields' => [],
                        'vaccination_fields' => [],
                        'documents' => [],
                    ],
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
