<?php

namespace App\Support\Employees;

final class EmployeeSmartSearchConceptRegistry
{
    public const OPERATOR_EQUALS = 'equals';

    public const OPERATOR_MISSING = 'missing';

    public const OPERATOR_PRESENT = 'present';

    /** @var list<string> */
    public const OPERATORS = [
        self::OPERATOR_EQUALS,
        self::OPERATOR_MISSING,
        self::OPERATOR_PRESENT,
    ];

    public const LOOKUP_NONE = 'none';

    public const LOOKUP_ENUM = 'enum';

    public const LOOKUP_NAMED = 'named';

    public const PRESENCE_STRING = 'string';

    public const PRESENCE_FOREIGN_KEY = 'foreign_key';

    public const PRESENCE_DATE = 'date';

    public const PRESENCE_COMPOSITE_EMAIL = 'composite_email';

    /**
     * Closed Employee Directory Smart Search concepts.
     *
     * The AI may only name these keys. Database columns, relations, and query
     * strategies stay server-owned.
     *
     * @return array<string, array{
     *     label: string,
     *     operators: list<string>,
     *     lookup: string,
     *     filter_key: string|null,
     *     presence: string|null,
     *     column: string|null,
     *     composite: bool,
     *     single_valued: bool,
     *     aliases: array<string, list<string>>
     * }>
     */
    public static function definitions(): array
    {
        return [
            'status' => [
                'label' => 'HR status',
                'operators' => [self::OPERATOR_EQUALS],
                'lookup' => self::LOOKUP_ENUM,
                'filter_key' => 'status',
                'presence' => null,
                'column' => 'status',
                'composite' => false,
                'single_valued' => true,
                'aliases' => [],
            ],
            'branch' => [
                'label' => 'Branch',
                'operators' => [self::OPERATOR_EQUALS, self::OPERATOR_MISSING, self::OPERATOR_PRESENT],
                'lookup' => self::LOOKUP_NAMED,
                'filter_key' => 'branch_id',
                'presence' => self::PRESENCE_FOREIGN_KEY,
                'column' => 'branch_id',
                'composite' => false,
                'single_valued' => true,
                'aliases' => [],
            ],
            'department' => [
                'label' => 'Department',
                'operators' => [self::OPERATOR_EQUALS, self::OPERATOR_MISSING, self::OPERATOR_PRESENT],
                'lookup' => self::LOOKUP_NAMED,
                'filter_key' => 'department_id',
                'presence' => self::PRESENCE_FOREIGN_KEY,
                'column' => 'department_id',
                'composite' => false,
                'single_valued' => true,
                'aliases' => [],
            ],
            'position' => [
                'label' => 'Position',
                'operators' => [self::OPERATOR_EQUALS, self::OPERATOR_MISSING, self::OPERATOR_PRESENT],
                'lookup' => self::LOOKUP_NAMED,
                'filter_key' => 'position_id',
                'presence' => self::PRESENCE_FOREIGN_KEY,
                'column' => 'position_id',
                'composite' => false,
                'single_valued' => true,
                'aliases' => [],
            ],
            'nationality' => [
                'label' => 'Nationality',
                'operators' => [self::OPERATOR_EQUALS, self::OPERATOR_MISSING, self::OPERATOR_PRESENT],
                'lookup' => self::LOOKUP_NAMED,
                'filter_key' => 'nationality_id',
                'presence' => self::PRESENCE_FOREIGN_KEY,
                'column' => 'nationality_id',
                'composite' => false,
                'single_valued' => true,
                'aliases' => [],
            ],
            'rank' => [
                'label' => 'Rank',
                'operators' => [self::OPERATOR_EQUALS, self::OPERATOR_MISSING, self::OPERATOR_PRESENT],
                'lookup' => self::LOOKUP_NAMED,
                'filter_key' => 'rank_id',
                'presence' => self::PRESENCE_FOREIGN_KEY,
                'column' => 'rank_id',
                'composite' => false,
                'single_valued' => true,
                'aliases' => self::rankAliases(),
            ],
            'gender' => [
                'label' => 'Gender',
                'operators' => [self::OPERATOR_EQUALS, self::OPERATOR_MISSING, self::OPERATOR_PRESENT],
                'lookup' => self::LOOKUP_NAMED,
                'filter_key' => 'gender_id',
                'presence' => self::PRESENCE_FOREIGN_KEY,
                'column' => 'gender_id',
                'composite' => false,
                'single_valued' => true,
                'aliases' => [],
            ],
            'visa_type' => [
                'label' => 'Visa type',
                'operators' => [self::OPERATOR_EQUALS, self::OPERATOR_MISSING, self::OPERATOR_PRESENT],
                'lookup' => self::LOOKUP_NAMED,
                'filter_key' => 'visa_type_id',
                'presence' => self::PRESENCE_FOREIGN_KEY,
                'column' => 'visa_type_id',
                'composite' => false,
                'single_valued' => true,
                'aliases' => [],
            ],
            'sponsor' => [
                'label' => 'Sponsor',
                'operators' => [self::OPERATOR_EQUALS, self::OPERATOR_MISSING, self::OPERATOR_PRESENT],
                'lookup' => self::LOOKUP_NAMED,
                'filter_key' => 'company_visa_type_id',
                'presence' => self::PRESENCE_FOREIGN_KEY,
                'column' => 'company_visa_type_id',
                'composite' => false,
                'single_valued' => true,
                'aliases' => [],
            ],
            'role' => [
                'label' => 'Role',
                'operators' => [self::OPERATOR_EQUALS],
                'lookup' => self::LOOKUP_NAMED,
                'filter_key' => 'role_id',
                'presence' => null,
                'column' => null,
                'composite' => false,
                'single_valued' => true,
                'aliases' => [],
            ],
            'approval_location' => [
                'label' => 'Approval location',
                'operators' => [self::OPERATOR_EQUALS],
                'lookup' => self::LOOKUP_NAMED,
                'filter_key' => 'approval_location_id',
                'presence' => null,
                'column' => null,
                'composite' => false,
                'single_valued' => false,
                'aliases' => [],
            ],
            'sssa_option' => [
                'label' => 'SSSA option',
                'operators' => [self::OPERATOR_EQUALS],
                'lookup' => self::LOOKUP_NAMED,
                'filter_key' => 'sssa_option_id',
                'presence' => null,
                'column' => null,
                'composite' => false,
                'single_valued' => false,
                'aliases' => [],
            ],
            'crew_status' => [
                'label' => 'Crew status',
                'operators' => [self::OPERATOR_EQUALS],
                'lookup' => self::LOOKUP_ENUM,
                'filter_key' => 'crew_status',
                'presence' => null,
                'column' => null,
                'composite' => false,
                'single_valued' => true,
                'aliases' => self::crewStatusAliases(),
            ],
            'emirates_id' => [
                'label' => 'Emirates ID',
                'operators' => [self::OPERATOR_MISSING, self::OPERATOR_PRESENT],
                'lookup' => self::LOOKUP_NONE,
                'filter_key' => null,
                'presence' => self::PRESENCE_STRING,
                'column' => 'emirates_id',
                'composite' => false,
                'single_valued' => true,
                'aliases' => [],
            ],
            'passport_number' => [
                'label' => 'Passport',
                'operators' => [self::OPERATOR_MISSING, self::OPERATOR_PRESENT],
                'lookup' => self::LOOKUP_NONE,
                'filter_key' => null,
                'presence' => self::PRESENCE_STRING,
                'column' => 'passport_number',
                'composite' => false,
                'single_valued' => true,
                'aliases' => [],
            ],
            'work_email' => [
                'label' => 'Work email',
                'operators' => [self::OPERATOR_MISSING, self::OPERATOR_PRESENT],
                'lookup' => self::LOOKUP_NONE,
                'filter_key' => null,
                'presence' => self::PRESENCE_STRING,
                'column' => 'work_email',
                'composite' => false,
                'single_valued' => true,
                'aliases' => [],
            ],
            'personal_email' => [
                'label' => 'Personal email',
                'operators' => [self::OPERATOR_MISSING, self::OPERATOR_PRESENT],
                'lookup' => self::LOOKUP_NONE,
                'filter_key' => null,
                'presence' => self::PRESENCE_STRING,
                'column' => 'personal_email',
                'composite' => false,
                'single_valued' => true,
                'aliases' => [],
            ],
            'email' => [
                'label' => 'Email',
                'operators' => [self::OPERATOR_MISSING, self::OPERATOR_PRESENT],
                'lookup' => self::LOOKUP_NONE,
                'filter_key' => null,
                'presence' => self::PRESENCE_COMPOSITE_EMAIL,
                'column' => null,
                'composite' => true,
                'single_valued' => true,
                'aliases' => [],
            ],
            'phone' => [
                'label' => 'Phone',
                'operators' => [self::OPERATOR_MISSING, self::OPERATOR_PRESENT],
                'lookup' => self::LOOKUP_NONE,
                'filter_key' => null,
                'presence' => self::PRESENCE_STRING,
                'column' => 'phone',
                'composite' => false,
                'single_valued' => true,
                'aliases' => [],
            ],
            'phone_home_country' => [
                'label' => 'Home country phone',
                'operators' => [self::OPERATOR_MISSING, self::OPERATOR_PRESENT],
                'lookup' => self::LOOKUP_NONE,
                'filter_key' => null,
                'presence' => self::PRESENCE_STRING,
                'column' => 'phone_home_country',
                'composite' => false,
                'single_valued' => true,
                'aliases' => [],
            ],
            'date_of_birth' => [
                'label' => 'Date of birth',
                'operators' => [self::OPERATOR_MISSING, self::OPERATOR_PRESENT],
                'lookup' => self::LOOKUP_NONE,
                'filter_key' => null,
                'presence' => self::PRESENCE_DATE,
                'column' => 'date_of_birth',
                'composite' => false,
                'single_valued' => true,
                'aliases' => [],
            ],
            'hire_date' => [
                'label' => 'Hire date',
                'operators' => [self::OPERATOR_MISSING, self::OPERATOR_PRESENT],
                'lookup' => self::LOOKUP_NONE,
                'filter_key' => null,
                'presence' => self::PRESENCE_DATE,
                'column' => 'hire_date',
                'composite' => false,
                'single_valued' => true,
                'aliases' => [],
            ],
            'nearest_airport' => [
                'label' => 'Nearest airport',
                'operators' => [self::OPERATOR_MISSING, self::OPERATOR_PRESENT],
                'lookup' => self::LOOKUP_NONE,
                'filter_key' => null,
                'presence' => self::PRESENCE_STRING,
                'column' => 'nearest_airport',
                'composite' => false,
                'single_valued' => true,
                'aliases' => [],
            ],
            'emergency_contact' => [
                'label' => 'Emergency contact',
                'operators' => [self::OPERATOR_MISSING, self::OPERATOR_PRESENT],
                'lookup' => self::LOOKUP_NONE,
                'filter_key' => null,
                'presence' => self::PRESENCE_STRING,
                'column' => 'emergency_contact',
                'composite' => false,
                'single_valued' => true,
                'aliases' => [],
            ],
            'emergency_phone' => [
                'label' => 'Emergency phone',
                'operators' => [self::OPERATOR_MISSING, self::OPERATOR_PRESENT],
                'lookup' => self::LOOKUP_NONE,
                'filter_key' => null,
                'presence' => self::PRESENCE_STRING,
                'column' => 'emergency_phone',
                'composite' => false,
                'single_valued' => true,
                'aliases' => [],
            ],
            'place_of_birth' => [
                'label' => 'Place of birth',
                'operators' => [self::OPERATOR_MISSING, self::OPERATOR_PRESENT],
                'lookup' => self::LOOKUP_NONE,
                'filter_key' => null,
                'presence' => self::PRESENCE_STRING,
                'column' => 'place_of_birth',
                'composite' => false,
                'single_valued' => true,
                'aliases' => [],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * @return list<string>
     */
    public static function presenceKeys(): array
    {
        return array_values(array_filter(
            self::keys(),
            fn (string $key): bool => self::definition($key)['presence'] !== null,
        ));
    }

    /**
     * @return array{
     *     label: string,
     *     operators: list<string>,
     *     lookup: string,
     *     filter_key: string|null,
     *     presence: string|null,
     *     column: string|null,
     *     composite: bool,
     *     single_valued: bool,
     *     aliases: array<string, list<string>>
     * }|null
     */
    public static function definition(string $concept): ?array
    {
        return self::definitions()[$concept] ?? null;
    }

    public static function has(string $concept): bool
    {
        return self::definition($concept) !== null;
    }

    public static function label(string $concept): string
    {
        return self::definition($concept)['label'] ?? $concept;
    }

    /**
     * @return list<string>
     */
    public static function operatorsFor(string $concept): array
    {
        return self::definition($concept)['operators'] ?? [];
    }

    public static function allows(string $concept, string $operator): bool
    {
        return in_array($operator, self::operatorsFor($concept), true);
    }

    public static function isSingleValued(string $concept): bool
    {
        return (bool) (self::definition($concept)['single_valued'] ?? true);
    }

    public static function isComposite(string $concept): bool
    {
        return (bool) (self::definition($concept)['composite'] ?? false);
    }

    /**
     * Concepts that must never be exposed through Smart Search.
     *
     * @return list<string>
     */
    public static function excludedConcepts(): array
    {
        return [
            'salary',
            'allowances',
            'payroll',
            'bank',
            'bank_account',
            'iban',
            'password',
            'credentials',
            'secrets',
            'company_id',
            'manager',
            'employee_no',
            'name',
        ];
    }

    /**
     * Server-owned rank terminology. Rank has no code column; aliases map
     * approved phrases onto trusted canonical names.
     *
     * @return array<string, list<string>>
     */
    private static function rankAliases(): array
    {
        return [
            'ab' => ['AB', 'Able Seaman'],
            'able seaman' => ['AB', 'Able Seaman'],
            'os' => ['OS', 'Ordinary Seaman'],
            'ordinary seaman' => ['OS', 'Ordinary Seaman'],
            'master' => ['Master'],
            'captain' => ['Master'],
        ];
    }

    /**
     * Extra phrases for current EmployeeCrewStatusFilter values.
     *
     * @return array<string, list<string>>
     */
    private static function crewStatusAliases(): array
    {
        return [
            'onboard' => ['on_vessel'],
            'on board' => ['on_vessel'],
            'on vessel' => ['on_vessel'],
            'at home' => ['in_home'],
            'in home' => ['in_home'],
            'available' => ['available'],
            'ready to join' => ['ready_to_join'],
            'pre-mobilisation' => ['pre_mobilisation'],
            'pre mobilisation' => ['pre_mobilisation'],
            'training' => ['training'],
            'demob standby' => ['demob_standby'],
        ];
    }
}
