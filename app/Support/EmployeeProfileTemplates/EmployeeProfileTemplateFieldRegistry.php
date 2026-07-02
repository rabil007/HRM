<?php

namespace App\Support\EmployeeProfileTemplates;

final class EmployeeProfileTemplateFieldRegistry
{
    /**
     * Employee fields hidden until enabled on a specific profile template.
     *
     * @var array<string, list<string>>
     */
    private const FIELDS_HIDDEN_BY_DEFAULT = [
        'employees' => ['crew_status'],
    ];

    /** @var list<string> */
    public const TAB_ORDER = [
        'personal',
        'contract',
        'bank',
        'education',
        'work_experience',
        'languages',
        'training',
        'sea_service',
        'documents',
        'vaccinations',
    ];

    /**
     * @return array<string, string>
     */
    public static function tabLabels(): array
    {
        return [
            'personal' => 'Personal',
            'contract' => 'Contract',
            'bank' => 'Bank',
            'education' => 'Education',
            'work_experience' => 'Work experience',
            'languages' => 'Languages',
            'training' => 'Training',
            'sea_service' => 'Sea service',
            'documents' => 'Documents',
            'vaccinations' => 'Vaccinations',
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function tabToTables(): array
    {
        return [
            'personal' => ['employees'],
            'contract' => ['employee_contracts'],
            'bank' => ['employee_bank_accounts'],
            'education' => ['employee_education_qualifications'],
            'work_experience' => ['employee_work_experiences'],
            'languages' => ['employee_languages'],
            'training' => ['employee_trainings'],
            'sea_service' => ['employee_sea_services'],
            'documents' => ['employee_documents'],
            'vaccinations' => ['employee_vaccinations'],
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function fieldsByTable(): array
    {
        return [
            'employees' => [
                'employee_no' => 'Employee number',
                'name' => 'Name',
                'image' => 'Photo',
                'branch_id' => 'Branch',
                'department_id' => 'Department',
                'position_id' => 'Position',
                'rank_id' => 'Rank',
                'project_id' => 'Project name',
                'date_of_birth' => 'Date of birth',
                'hire_date' => 'Date of hire',
                'place_of_birth' => 'Place of birth',
                'gender_id' => 'Gender',
                'religion_id' => 'Religion',
                'visa_type_id' => 'Visa type',
                'company_visa_type_id' => 'Sponsor',
                'approval_location_ids' => 'Approval locations',
                'sssa_option_ids' => 'SSSA options',
                'nationality_id' => 'Nationality',
                'marital_status' => 'Marital status',
                'spouse_name' => 'Spouse name',
                'personal_email' => 'Personal email',
                'work_email' => 'Work email',
                'phone' => 'Phone',
                'phone_home_country' => 'Home country phone',
                'nearest_airport' => 'Nearest airport',
                'emergency_contact' => 'Emergency contact',
                'emergency_phone' => 'Emergency phone',
                'address' => 'Address',
                'emirates_id' => 'Emirates ID',
                'passport_number' => 'Passport number',
                'labor_card_number' => 'Labor card number',
                'salary_payment_method' => 'Salary payment method',
                'status' => 'Status',
                'crew_status' => 'Crew status',
            ],
            'employee_contracts' => [
                'contract_type' => 'Contract type',
                'payroll_category' => 'Payroll category',
                'start_date' => 'Start date',
                'end_date' => 'End date',
                'labor_contract_id' => 'Labor contract ID',
                'basic_salary' => 'Basic salary',
                'housing_allowance' => 'Housing allowance',
                'transport_allowance' => 'Transport allowance',
                'other_allowances' => 'Other allowances',
                'supplementary_allowance' => 'Supplementary allowance',
                'site_allowance' => 'Site allowance',
                'note' => 'Note',
                'status' => 'Status',
            ],
            'employee_bank_accounts' => [
                'bank_id' => 'Bank',
                'iban' => 'IBAN',
                'account_name' => 'Account name',
                'is_primary' => 'Primary account',
            ],
            'employee_education_qualifications' => [
                'certificate' => 'Certificate',
                'issue_date' => 'Issue date',
                'university' => 'University',
                'country_id' => 'Country',
            ],
            'employee_work_experiences' => [
                'company_name' => 'Company name',
                'job_title' => 'Job title',
                'date_from' => 'Date from',
                'date_to' => 'Date to',
                'responsibility' => 'Responsibility',
            ],
            'employee_languages' => [
                'language_name' => 'Language',
                'is_spoken' => 'Spoken',
                'is_written' => 'Written',
                'is_understood' => 'Understood',
                'is_mother_tongue' => 'Mother tongue',
            ],
            'employee_trainings' => [
                'course_id' => 'Course',
                'issue_date' => 'Issue date',
                'expiry_date' => 'Expiry date',
                'institute_center' => 'Institute / center',
                'country_id' => 'Country',
                'certificate_path' => 'Certificate file',
            ],
            'employee_sea_services' => [
                'vessel_type_id' => 'Vessel type',
                'vessel_id' => 'Vessel',
                'rank_id' => 'Rank',
                'start_date' => 'Start date',
                'end_date' => 'End date',
                'client_id' => 'Client',
                'is_offshore' => 'Offshore',
            ],
            'employee_documents' => [
                'document_type_id' => 'Document type',
                'title' => 'Title',
                'issue_date' => 'Issue date',
                'expiry_date' => 'Expiry date',
                'document_number' => 'Document number',
                'notes' => 'Notes',
            ],
            'employee_vaccinations' => [
                'vaccination_name' => 'Vaccination name',
                'country_id' => 'Country',
                'first_dose_date' => 'First dose date',
                'second_dose_date' => 'Second dose date',
                'booster_dose_date' => 'Booster dose date',
            ],
        ];
    }

    /**
     * @return array{version: int, tabs: array<string, array{visible: bool}>, fields: array<string, array<string, array{visible: bool, required: bool}>>}
     */
    public static function defaultConfiguration(): array
    {
        $tabs = [];
        foreach (self::TAB_ORDER as $tabKey) {
            $tabs[$tabKey] = ['visible' => true];
        }

        $fields = [];
        foreach (self::fieldsByTable() as $table => $tableFields) {
            $fields[$table] = [];
            $defaultRequired = EmployeeProfileTemplateRequestRules::DEFAULT_REQUIRED_BY_TABLE[$table] ?? [];

            foreach (array_keys($tableFields) as $fieldKey) {
                $hiddenByDefault = in_array($fieldKey, self::FIELDS_HIDDEN_BY_DEFAULT[$table] ?? [], true);

                $fields[$table][$fieldKey] = [
                    'visible' => ! $hiddenByDefault,
                    'required' => in_array($fieldKey, $defaultRequired, true),
                ];
            }
        }

        return [
            'version' => 1,
            'tabs' => $tabs,
            'fields' => $fields,
        ];
    }

    /**
     * @return list<string>
     */
    public static function allTableKeys(): array
    {
        return array_keys(self::fieldsByTable());
    }

    /**
     * @return list<string>
     */
    public static function allTabKeys(): array
    {
        return self::TAB_ORDER;
    }
}
