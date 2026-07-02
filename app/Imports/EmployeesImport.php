<?php

namespace App\Imports;

use App\Models\Bank;
use App\Models\Branch;
use App\Models\Country;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeProfileTemplate;
use App\Models\Gender;
use App\Models\Position;
use App\Models\Religion;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateRequestRules;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Facades\Excel;

class EmployeesImport
{
    public const MAX_ROWS = 1000;

    /**
     * Map of canonical field => list of accepted aliases (lowercase, snake/space tolerant).
     *
     * @var array<string, array<int, string>>
     */
    public const FIELD_ALIASES = [
        'employee_no' => ['employee_no', 'employee number', 'emp no', 'emp_no', 'employee id', 'employee_id'],
        'name' => ['name', 'employee name', 'full name'],
        'work_email' => ['work_email', 'work email', 'company email', 'office email'],
        'personal_email' => ['personal_email', 'personal email', 'private email', 'email'],
        'phone' => ['phone', 'mobile', 'phone number', 'work phone', 'phone_uae', 'phone (uae)'],
        'phone_home_country' => ['phone_home_country', 'phone home country', 'home country phone'],
        'date_of_birth' => ['date_of_birth', 'date of birth', 'dob', 'birthday', 'birth date'],
        'hire_date' => ['hire_date', 'hire date', 'date of hire', 'date hired', 'hired on'],
        'place_of_birth' => ['place_of_birth', 'place of birth', 'birthplace'],
        'marital_status' => ['marital_status', 'marital status', 'civil status'],
        'spouse_name' => ['spouse_name', 'spouse name'],
        'address' => ['address', 'home address', 'residence'],
        'nearest_airport' => ['nearest_airport', 'nearest airport', 'airport'],
        'emergency_contact' => ['emergency_contact', 'emergency contact', 'emergency name'],
        'emergency_phone' => ['emergency_phone', 'emergency phone'],
        'passport_number' => ['passport_number', 'passport no', 'passport number', 'passport'],
        'emirates_id' => ['emirates_id', 'emirates id', 'eid'],
        'labor_card_number' => ['labor_card_number', 'labor card', 'labor card number', 'labour card'],
        'labor_contract_id' => ['labor_contract_id', 'labor contract id', 'labour contract id'],
        'iban' => ['iban', 'iban number'],
        'basic_salary' => ['basic_salary', 'basic salary', 'salary'],
        'housing_allowance' => ['housing_allowance', 'housing allowance', 'housing'],
        'transport_allowance' => ['transport_allowance', 'transport allowance', 'transport'],
        'other_allowances' => ['other_allowances', 'other allowances', 'allowances'],
        'supplementary_allowance' => [
            'supplementary_allowance',
            'supplementary allowance',
            'supplimentary allowance',
            'supplimentry allowance',
        ],
        'site_allowance' => ['site_allowance', 'site allowance'],
        'note' => ['note', 'contract note', 'contract reason', 'contract change reason'],
        'start_date' => ['start_date', 'start date', 'joining date', 'date of joining', 'doj', 'contract start'],
        'end_date' => ['end_date', 'end date', 'termination date'],
        'contract_type' => ['contract_type', 'contract type', 'contract'],
        'status' => ['status'],
        'branch' => ['branch', 'branch name'],
        'department' => ['department', 'department name', 'dept'],
        'position' => ['position', 'position title', 'job title', 'title'],
        'gender' => ['gender'],
        'religion' => ['religion'],
        'nationality' => ['nationality', 'country'],
        'bank' => ['bank', 'bank name'],
        'account_name' => ['account_name', 'account name', 'account holder'],
    ];

    public const REQUIRED_FIELDS = ['employee_no', 'name'];

    /**
     * Import fields that may be omitted from the file; execute() applies defaults.
     *
     * @var list<string>
     */
    public const IMPORT_DEFAULTABLE_FIELDS = [
        'contract_type',
        'start_date',
    ];

    /**
     * Import column => [template table, template field key].
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function importFieldTemplateMap(): array
    {
        return [
            'employee_no' => ['employees', 'employee_no'],
            'name' => ['employees', 'name'],
            'work_email' => ['employees', 'work_email'],
            'personal_email' => ['employees', 'personal_email'],
            'phone' => ['employees', 'phone'],
            'phone_home_country' => ['employees', 'phone_home_country'],
            'date_of_birth' => ['employees', 'date_of_birth'],
            'hire_date' => ['employees', 'hire_date'],
            'place_of_birth' => ['employees', 'place_of_birth'],
            'marital_status' => ['employees', 'marital_status'],
            'spouse_name' => ['employees', 'spouse_name'],
            'address' => ['employees', 'address'],
            'nearest_airport' => ['employees', 'nearest_airport'],
            'emergency_contact' => ['employees', 'emergency_contact'],
            'emergency_phone' => ['employees', 'emergency_phone'],
            'passport_number' => ['employees', 'passport_number'],
            'emirates_id' => ['employees', 'emirates_id'],
            'labor_card_number' => ['employees', 'labor_card_number'],
            'branch' => ['employees', 'branch_id'],
            'department' => ['employees', 'department_id'],
            'position' => ['employees', 'position_id'],
            'gender' => ['employees', 'gender_id'],
            'religion' => ['employees', 'religion_id'],
            'nationality' => ['employees', 'nationality_id'],
            'status' => ['employees', 'status'],
            'contract_type' => ['employee_contracts', 'contract_type'],
            'start_date' => ['employee_contracts', 'start_date'],
            'end_date' => ['employee_contracts', 'end_date'],
            'labor_contract_id' => ['employee_contracts', 'labor_contract_id'],
            'basic_salary' => ['employee_contracts', 'basic_salary'],
            'housing_allowance' => ['employee_contracts', 'housing_allowance'],
            'transport_allowance' => ['employee_contracts', 'transport_allowance'],
            'other_allowances' => ['employee_contracts', 'other_allowances'],
            'supplementary_allowance' => ['employee_contracts', 'supplementary_allowance'],
            'site_allowance' => ['employee_contracts', 'site_allowance'],
            'note' => ['employee_contracts', 'note'],
            'bank' => ['employee_bank_accounts', 'bank_id'],
            'iban' => ['employee_bank_accounts', 'iban'],
            'account_name' => ['employee_bank_accounts', 'account_name'],
        ];
    }

    public const SENSITIVE_FIELD_PERMISSIONS = [
        'passport_number' => 'employees.identity.import',
        'emirates_id' => 'employees.identity.import',
        'labor_card_number' => 'employees.identity.import',
        'labor_contract_id' => 'employees.identity.import',
        'basic_salary' => 'employees.contracts.import',
        'housing_allowance' => 'employees.contracts.import',
        'transport_allowance' => 'employees.contracts.import',
        'other_allowances' => 'employees.contracts.import',
        'supplementary_allowance' => 'employees.contracts.import',
        'site_allowance' => 'employees.contracts.import',
        'bank' => 'employees.bank_accounts.import',
        'iban' => 'employees.bank_accounts.import',
        'account_name' => 'employees.bank_accounts.import',
    ];

    public const IMPORT_MIME_TYPES = [
        'text/csv',
        'text/plain',
        'application/csv',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    private const IMPORT_DATE_FIELDS = [
        'date_of_birth',
        'hire_date',
        'start_date',
        'end_date',
    ];

    private const IMPORT_NUMERIC_FIELDS = [
        'basic_salary',
        'housing_allowance',
        'transport_allowance',
        'other_allowances',
        'supplementary_allowance',
        'site_allowance',
        'supplementary_allowance',
        'site_allowance',
    ];

    public const TEMPLATE_HEADERS = [
        'employee_no',
        'name',
        'work_email',
        'personal_email',
        'phone',
        'phone_home_country',
        'date_of_birth',
        'hire_date',
        'place_of_birth',
        'marital_status',
        'spouse_name',
        'address',
        'nearest_airport',
        'emergency_contact',
        'emergency_phone',
        'passport_number',
        'emirates_id',
        'labor_card_number',
        'branch',
        'department',
        'position',
        'gender',
        'religion',
        'nationality',
        'contract_type',
        'start_date',
        'end_date',
        'labor_contract_id',
        'basic_salary',
        'housing_allowance',
        'transport_allowance',
        'other_allowances',
        'supplementary_allowance',
        'site_allowance',
        'note',
        'bank',
        'iban',
        'account_name',
        'status',
    ];

    /**
     * @var array<string, int>|null
     */
    private ?array $branchMap = null;

    /**
     * @var array<string, int>|null
     */
    private ?array $departmentMap = null;

    /**
     * @var array<string, int>|null
     */
    private ?array $positionMap = null;

    /**
     * @var array<string, int>|null
     */
    private ?array $genderMap = null;

    /**
     * @var array<string, int>|null
     */
    private ?array $religionMap = null;

    /**
     * @var array<string, int>|null
     */
    private ?array $countryMap = null;

    /**
     * @var array<string, int>|null
     */
    private ?array $bankMap = null;

    public function __construct(public int $companyId, public int $actorId) {}

    /**
     * Read the heading row of an uploaded file.
     *
     * @return array<int, string>
     */
    public function readHeaders(UploadedFile $file): array
    {
        $reader = new class implements ToArray
        {
            public function array(array $array): array
            {
                return $array;
            }
        };

        $rows = Excel::toArray($reader, $file)[0][0] ?? [];

        return collect($rows)
            ->map(fn ($value) => is_string($value) ? trim($value) : (string) $value)
            ->filter(fn ($value) => $value !== '')
            ->values()
            ->all();
    }

    /**
     * Read the file rows as associative arrays keyed by header.
     *
     * @return array<int, array<string, mixed>>
     */
    public function readRows(UploadedFile $file, ?int $limit = null): array
    {
        $reader = new class implements ToArray
        {
            public function array(array $array): array
            {
                return $array;
            }
        };

        $sheets = Excel::toArray($reader, $file);
        $rows = $sheets[0] ?? [];

        if (empty($rows)) {
            return [];
        }

        $headers = array_map(fn ($value) => is_string($value) ? trim($value) : (string) $value, $rows[0]);
        $body = array_slice($rows, 1);

        $associative = [];

        foreach ($body as $row) {
            if (! is_array($row)) {
                continue;
            }

            $isEmpty = collect($row)->every(fn ($value) => $value === null || $value === '' || $value === false);

            if ($isEmpty) {
                continue;
            }

            $assoc = [];

            foreach ($headers as $index => $header) {
                $assoc[$header] = $row[$index] ?? null;
            }

            $associative[] = $assoc;

            if ($limit !== null && count($associative) >= $limit) {
                break;
            }
        }

        return $associative;
    }

    /**
     * @return array<int, string>
     */
    public static function fields(): array
    {
        return array_keys(self::FIELD_ALIASES);
    }

    /**
     * Auto-map file headers to canonical employee fields.
     *
     * @param  array<int, string>  $headers
     * @return array<string, string|null> field => header (or null)
     */
    public function autoMap(array $headers): array
    {
        $normalized = collect($headers)
            ->mapWithKeys(fn ($header) => [self::normalize($header) => $header])
            ->all();

        $mapping = [];

        foreach (self::FIELD_ALIASES as $field => $aliases) {
            $mapping[$field] = null;

            foreach ($aliases as $alias) {
                $key = self::normalize($alias);

                if (isset($normalized[$key])) {
                    $mapping[$field] = $normalized[$key];

                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<string, mixed>|null  $mapping
     * @param  array<int, string>|null  $allowedFields
     * @return array<string, string|null>
     */
    public function sanitizeMapping(array $headers, ?array $mapping, ?array $allowedFields = null): array
    {
        $source = $mapping ? $mapping : $this->autoMap($headers);
        $allowed = $allowedFields ? array_fill_keys($allowedFields, true) : null;
        $headerLookup = array_fill_keys($headers, true);
        $sanitized = [];

        foreach (self::fields() as $field) {
            $header = $source[$field] ?? null;

            if ($allowed !== null && ! isset($allowed[$field])) {
                $sanitized[$field] = null;

                continue;
            }

            if (! is_string($header)) {
                $sanitized[$field] = null;

                continue;
            }

            $header = trim($header);
            $sanitized[$field] = $header !== '' && isset($headerLookup[$header]) ? $header : null;
        }

        return $sanitized;
    }

    /**
     * Validate parsed rows and return [validRows, errors, summary].
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, string|null>  $mapping
     * @return array{rows: array<int, array<string, mixed>>, errors: array<int, array{row: int, field: string, message: string}>, summary: array{total: int, valid: int, invalid: int}}
     */
    public function validateRows(array $rows, array $mapping, ?EmployeeProfileTemplate $template = null): array
    {
        $this->primeLookups();

        $validRows = [];
        $errors = [];

        $duplicateNos = [];
        $existingNos = $this->existingEmployeeNos();
        $rules = $this->validationRulesForTemplate($template, $mapping);

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $shaped = $this->shapeRow($row, $mapping);

            $validator = Validator::make($shaped, $rules);

            if ($validator->fails()) {
                foreach ($validator->errors()->messages() as $field => $messages) {
                    foreach ($messages as $message) {
                        $errors[] = [
                            'row' => $rowNumber,
                            'field' => $field,
                            'message' => $message,
                        ];
                    }
                }
            }

            $no = $shaped['employee_no'] ?? null;

            if ($no) {
                if (isset($duplicateNos[$no])) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'field' => 'employee_no',
                        'message' => "Duplicate of row {$duplicateNos[$no]} in this file.",
                    ];
                } else {
                    $duplicateNos[$no] = $rowNumber;
                }

                if (in_array(strtolower($no), $existingNos, true)) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'field' => 'employee_no',
                        'message' => 'Employee number already exists in this company.',
                    ];
                }
            }

            $unresolved = $this->resolveLookups($shaped);

            foreach ($unresolved as $field => $message) {
                $errors[] = [
                    'row' => $rowNumber,
                    'field' => $field,
                    'message' => $message,
                ];
            }

            $validRows[] = $shaped;
        }

        $invalidRows = collect($errors)->pluck('row')->unique()->count();

        return [
            'rows' => $validRows,
            'errors' => $errors,
            'summary' => [
                'total' => count($validRows),
                'valid' => max(0, count($validRows) - $invalidRows),
                'invalid' => $invalidRows,
            ],
        ];
    }

    /**
     * Persist employees and optional bank account.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{created: int, failed: array<int, array{row: int, message: string}>}
     */
    public function execute(array $rows, ?int $onboardingTemplateId = null): array
    {
        $this->primeLookups();
        $existingNos = $this->existingEmployeeNos();

        $created = 0;
        $failed = [];

        $importTemplate = $onboardingTemplateId !== null
            ? EmployeeProfileTemplate::query()->find($onboardingTemplateId)
            : null;
        $importTabs = $importTemplate !== null
            ? EmployeeProfileTemplateResolver::resolve($importTemplate)['tabs']
            : null;
        $bankTabVisible = $importTabs === null || (bool) ($importTabs['bank']['visible'] ?? true);

        DB::transaction(function () use ($rows, $existingNos, $onboardingTemplateId, $bankTabVisible, &$created, &$failed) {
            foreach ($rows as $index => $row) {
                $rowNumber = $index + 2;
                $no = (string) ($row['employee_no'] ?? '');

                if ($no === '' || in_array(strtolower($no), $existingNos, true)) {
                    $failed[] = [
                        'row' => $rowNumber,
                        'message' => $no === '' ? 'Missing employee number.' : 'Employee number already exists.',
                    ];

                    continue;
                }

                try {
                    $resolved = $this->resolveIdsForInsert($row);

                    $employee = Employee::create([
                        'company_id' => $this->companyId,
                        'employee_profile_template_id' => $onboardingTemplateId,
                        'employee_no' => $no,
                        'name' => $row['name'] ?? null,
                        'work_email' => $row['work_email'] ?? null,
                        'personal_email' => $row['personal_email'] ?? null,
                        'phone' => $row['phone'] ?? null,
                        'phone_home_country' => $row['phone_home_country'] ?? null,
                        'date_of_birth' => $row['date_of_birth'] ?? null,
                        'hire_date' => $row['hire_date'] ?? null,
                        'place_of_birth' => $row['place_of_birth'] ?? null,
                        'marital_status' => $row['marital_status'] ?: null,
                        'spouse_name' => $row['spouse_name'] ?? null,
                        'address' => $row['address'] ?? null,
                        'nearest_airport' => $row['nearest_airport'] ?? null,
                        'emergency_contact' => $row['emergency_contact'] ?? null,
                        'emergency_phone' => $row['emergency_phone'] ?? null,
                        'passport_number' => $row['passport_number'] ?? null,
                        'emirates_id' => $row['emirates_id'] ?? null,
                        'labor_card_number' => $row['labor_card_number'] ?? null,
                        'branch_id' => $resolved['branch_id'] ?? null,
                        'department_id' => $resolved['department_id'] ?? null,
                        'position_id' => $resolved['position_id'] ?? null,
                        'gender_id' => $resolved['gender_id'] ?? null,
                        'religion_id' => $resolved['religion_id'] ?? null,
                        'nationality_id' => $resolved['nationality_id'] ?? null,
                        'status' => $row['status'] ?: 'active',
                    ]);

                    $bankId = $resolved['bank_id'] ?? null;
                    $iban = $row['iban'] ?? null;
                    $accountName = $row['account_name'] ?? null;

                    if ($bankTabVisible && ($bankId || $iban || $accountName)) {
                        EmployeeBankAccount::query()->create([
                            'company_id' => $this->companyId,
                            'employee_id' => $employee->id,
                            'bank_id' => $bankId,
                            'iban' => $iban,
                            'account_name' => $accountName,
                            'is_primary' => true,
                        ]);
                    }

                    $existingNos[] = strtolower($no);
                    $created++;
                } catch (\Throwable $e) {
                    $failed[] = [
                        'row' => $rowNumber,
                        'message' => $e->getMessage(),
                    ];
                }
            }
        });

        return [
            'created' => $created,
            'failed' => $failed,
        ];
    }

    /**
     * Build the canonical row from raw cells using the mapping.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, string|null>  $mapping
     * @return array<string, mixed>
     */
    /**
     * @return array<string, list<string|object>>
     */
    /**
     * @param  array<string, string|null>  $mapping
     * @return array<string, list<string|object>>
     */
    private function validationRulesForTemplate(?EmployeeProfileTemplate $template, array $mapping): array
    {
        $rules = [
            'employee_no' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:200'],
            'work_email' => ['nullable', 'email', 'max:200'],
            'personal_email' => ['nullable', 'email', 'max:200'],
            'phone' => ['nullable', 'string', 'max:30'],
            'phone_home_country' => ['nullable', 'string', 'max:30'],
            'date_of_birth' => ['nullable', 'date'],
            'hire_date' => ['nullable', 'date'],
            'place_of_birth' => ['nullable', 'string', 'max:150'],
            'marital_status' => ['nullable', 'in:single,married,divorced,widowed'],
            'spouse_name' => ['nullable', 'string', 'max:200'],
            'address' => ['nullable', 'string'],
            'nearest_airport' => ['nullable', 'string', 'max:150'],
            'emergency_contact' => ['nullable', 'string', 'max:200'],
            'emergency_phone' => ['nullable', 'string', 'max:30'],
            'passport_number' => ['nullable', 'string', 'max:50'],
            'emirates_id' => ['nullable', 'string', 'max:30'],
            'labor_card_number' => ['nullable', 'string', 'max:100'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'contract_type' => ['nullable', 'in:limited,unlimited,part_time,contract'],
            'labor_contract_id' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive,on_leave,terminated'],
            'basic_salary' => ['nullable', 'numeric', 'min:0'],
            'housing_allowance' => ['nullable', 'numeric', 'min:0'],
            'transport_allowance' => ['nullable', 'numeric', 'min:0'],
            'other_allowances' => ['nullable', 'numeric', 'min:0'],
            'supplementary_allowance' => ['nullable', 'numeric', 'min:0'],
            'site_allowance' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
            'iban' => ['nullable', 'string', 'max:50'],
            'account_name' => ['nullable', 'string', 'max:200'],
        ];

        if ($template === null) {
            return $rules;
        }

        $employee = new Employee;
        $employee->setRelation('employeeProfileTemplate', $template);

        foreach (self::importFieldTemplateMap() as $importField => [$table, $fieldKey]) {
            if (! isset($rules[$importField])) {
                continue;
            }

            $adjusted = EmployeeProfileTemplateRequestRules::applyToRules(
                $employee,
                $table,
                [$fieldKey => $rules[$importField]],
            );

            $rules[$importField] = $adjusted[$fieldKey];
        }

        foreach (self::IMPORT_DEFAULTABLE_FIELDS as $field) {
            if (($mapping[$field] ?? null) !== null || ! isset($rules[$field])) {
                continue;
            }

            $rules[$field] = $this->importRulesAsOptional($rules[$field]);
        }

        return $rules;
    }

    /**
     * @param  list<mixed>  $rules
     * @return list<mixed>
     */
    private function importRulesAsOptional(array $rules): array
    {
        $filtered = array_values(array_filter(
            $rules,
            static fn (mixed $rule): bool => $rule !== 'required',
        ));

        if (! in_array('nullable', $filtered, true)) {
            array_unshift($filtered, 'nullable');
        }

        return $filtered;
    }

    private function shapeRow(array $row, array $mapping): array
    {
        $shaped = [];

        foreach (array_keys(self::FIELD_ALIASES) as $field) {
            $header = $mapping[$field] ?? null;
            $value = $header !== null ? ($row[$header] ?? null) : null;

            if (in_array($field, self::IMPORT_DATE_FIELDS, true)) {
                if (is_string($value)) {
                    $value = trim($value);

                    if ($value === '') {
                        $value = null;
                    }
                }
            } elseif (in_array($field, self::IMPORT_NUMERIC_FIELDS, true)) {
                if (is_string($value)) {
                    $value = trim($value);

                    if ($value === '') {
                        $value = null;
                    }
                }
            } else {
                $value = self::coerceStringCell($value);
            }

            $shaped[$field] = $value;
        }

        $shaped = $this->normaliseDateFields($shaped, [
            'date_of_birth',
            'hire_date',
            'start_date',
            'end_date',
        ]);

        if (isset($shaped['marital_status']) && is_string($shaped['marital_status'])) {
            $shaped['marital_status'] = strtolower($shaped['marital_status']);
        }

        if (isset($shaped['status']) && is_string($shaped['status'])) {
            $shaped['status'] = strtolower($shaped['status']);
        }

        if (isset($shaped['contract_type']) && is_string($shaped['contract_type'])) {
            $shaped['contract_type'] = strtolower(str_replace([' ', '-'], '_', $shaped['contract_type']));
        }

        return $shaped;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, string> unresolved-field => message
     */
    private function resolveLookups(array $row): array
    {
        $unresolved = [];

        foreach (['branch', 'department', 'position'] as $key) {
            if (! empty($row[$key])) {
                $map = $key === 'branch' ? $this->branchMap : ($key === 'department' ? $this->departmentMap : $this->positionMap);
                $name = self::normalize((string) $row[$key]);

                if (! isset($map[$name])) {
                    $unresolved[$key] = sprintf('"%s" not found in %s.', $row[$key], Str::plural($key));
                }
            }
        }

        foreach (['gender' => $this->genderMap, 'religion' => $this->religionMap, 'nationality' => $this->countryMap, 'bank' => $this->bankMap] as $key => $map) {
            if (! empty($row[$key])) {
                $name = self::normalize((string) $row[$key]);

                if (! isset($map[$name])) {
                    $unresolved[$key] = sprintf('"%s" not found in %s.', $row[$key], $key);
                }
            }
        }

        return $unresolved;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, int|null>
     */
    private function resolveIdsForInsert(array $row): array
    {
        $resolved = [
            'branch_id' => null,
            'department_id' => null,
            'position_id' => null,
            'gender_id' => null,
            'religion_id' => null,
            'nationality_id' => null,
            'bank_id' => null,
        ];

        foreach (['branch' => 'branch_id', 'department' => 'department_id', 'position' => 'position_id'] as $key => $field) {
            if (! empty($row[$key])) {
                $name = self::normalize((string) $row[$key]);
                $map = $key === 'branch' ? $this->branchMap : ($key === 'department' ? $this->departmentMap : $this->positionMap);
                $resolved[$field] = $map[$name] ?? null;
            }
        }

        foreach (['gender' => 'gender_id', 'religion' => 'religion_id', 'nationality' => 'nationality_id', 'bank' => 'bank_id'] as $key => $field) {
            if (! empty($row[$key])) {
                $name = self::normalize((string) $row[$key]);
                $map = $key === 'gender' ? $this->genderMap : ($key === 'religion' ? $this->religionMap : ($key === 'nationality' ? $this->countryMap : $this->bankMap));
                $resolved[$field] = $map[$name] ?? null;
            }
        }

        return $resolved;
    }

    private function primeLookups(): void
    {
        if ($this->branchMap !== null) {
            return;
        }

        $this->branchMap = Branch::query()
            ->where('company_id', $this->companyId)
            ->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [self::normalize((string) $name) => (int) $id])
            ->all();

        $this->departmentMap = Department::query()
            ->where('company_id', $this->companyId)
            ->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [self::normalize((string) $name) => (int) $id])
            ->all();

        $this->positionMap = Position::query()
            ->where('company_id', $this->companyId)
            ->pluck('id', 'title')
            ->mapWithKeys(fn ($id, $title) => [self::normalize((string) $title) => (int) $id])
            ->all();

        $this->genderMap = Gender::query()
            ->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [self::normalize((string) $name) => (int) $id])
            ->all();

        $this->religionMap = Religion::query()
            ->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [self::normalize((string) $name) => (int) $id])
            ->all();

        $this->countryMap = Country::query()
            ->get(['id', 'name', 'code'])
            ->reduce(function (array $carry, $country) {
                $carry[self::normalize((string) $country->name)] = (int) $country->id;

                if ($country->code) {
                    $carry[self::normalize((string) $country->code)] = (int) $country->id;
                }

                return $carry;
            }, []);

        $this->bankMap = Bank::query()
            ->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [self::normalize((string) $name) => (int) $id])
            ->all();
    }

    /**
     * @return array<int, string> lower-cased existing employee numbers
     */
    private function existingEmployeeNos(): array
    {
        return Employee::query()
            ->where('company_id', $this->companyId)
            ->pluck('employee_no')
            ->map(fn ($no) => strtolower((string) $no))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    private function normaliseDateFields(array $row, array $fields): array
    {
        foreach ($fields as $field) {
            if (! isset($row[$field]) || $row[$field] === null || $row[$field] === '') {
                continue;
            }

            $value = $row[$field];

            if (is_numeric($value)) {
                try {
                    $row[$field] = Carbon::createFromTimestamp(
                        ($value - 25569) * 86400
                    )->format('Y-m-d');

                    continue;
                } catch (\Throwable) {
                    // fall through
                }
            }

            try {
                $row[$field] = Carbon::parse((string) $value)->format('Y-m-d');
            } catch (\Throwable) {
                $row[$field] = $value;
            }
        }

        return $row;
    }

    public static function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '', $value) ?? $value;

        return $value;
    }

    private static function coerceStringCell(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                return null;
            }

            $nearest = round($value, 0, PHP_ROUND_HALF_UP);

            if (abs($value - $nearest) < 1e-9 && abs($nearest) <= (float) PHP_INT_MAX) {
                return (string) (int) $nearest;
            }

            $formatted = rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');

            return $formatted === '' ? null : $formatted;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
