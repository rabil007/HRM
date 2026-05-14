<?php

namespace App\Imports;

use App\Models\Bank;
use App\Models\Branch;
use App\Models\Country;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeContract;
use App\Models\Gender;
use App\Models\Position;
use App\Models\Religion;
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
        'place_of_birth' => ['place_of_birth', 'place of birth', 'birthplace'],
        'marital_status' => ['marital_status', 'marital status', 'civil status'],
        'spouse_name' => ['spouse_name', 'spouse name'],
        'spouse_birthdate' => ['spouse_birthdate', 'spouse birthdate', 'spouse dob'],
        'dependent_children_count' => ['dependent_children_count', 'dependent children', 'children count', 'children'],
        'address' => ['address', 'home address', 'residence'],
        'nearest_airport' => ['nearest_airport', 'nearest airport', 'airport'],
        'cv_source' => ['cv_source', 'cv source', 'source of cv', 'source'],
        'emergency_contact' => ['emergency_contact', 'emergency contact', 'emergency name'],
        'emergency_phone' => ['emergency_phone', 'emergency phone'],
        'emergency_contact_home_country' => ['emergency_contact_home_country', 'emergency contact home country', 'home country emergency contact'],
        'emergency_phone_home_country' => ['emergency_phone_home_country', 'emergency phone home country', 'home country emergency phone'],
        'passport_number' => ['passport_number', 'passport no', 'passport number', 'passport'],
        'emirates_id' => ['emirates_id', 'emirates id', 'eid'],
        'labor_card_number' => ['labor_card_number', 'labor card', 'labor card number', 'labour card'],
        'labor_contract_id' => ['labor_contract_id', 'labor contract id', 'labour contract id'],
        'iban' => ['iban', 'iban number'],
        'basic_salary' => ['basic_salary', 'basic salary', 'salary'],
        'housing_allowance' => ['housing_allowance', 'housing allowance', 'housing'],
        'transport_allowance' => ['transport_allowance', 'transport allowance', 'transport'],
        'other_allowances' => ['other_allowances', 'other allowances', 'allowances'],
        'start_date' => ['start_date', 'start date', 'joining date', 'date of joining', 'doj', 'hire date'],
        'end_date' => ['end_date', 'end date', 'termination date'],
        'probation_end_date' => ['probation_end_date', 'probation end date', 'probation ends'],
        'contract_type' => ['contract_type', 'contract type', 'contract'],
        'status' => ['status'],
        'branch' => ['branch', 'branch name'],
        'department' => ['department', 'department name', 'dept'],
        'position' => ['position', 'position title', 'job title', 'title'],
        'manager' => ['manager', 'manager name', 'reports to'],
        'manager_employee_no' => ['manager_employee_no', 'manager employee no', 'manager id', 'reports to id'],
        'gender' => ['gender'],
        'religion' => ['religion'],
        'nationality' => ['nationality', 'country'],
        'bank' => ['bank', 'bank name'],
        'account_name' => ['account_name', 'account name', 'account holder'],
    ];

    public const REQUIRED_FIELDS = ['employee_no', 'name', 'start_date', 'contract_type'];

    public const SENSITIVE_FIELD_PERMISSIONS = [
        'passport_number' => 'employees.import.identity',
        'emirates_id' => 'employees.import.identity',
        'labor_card_number' => 'employees.import.identity',
        'labor_contract_id' => 'employees.import.identity',
        'basic_salary' => 'employees.import.payroll',
        'housing_allowance' => 'employees.import.payroll',
        'transport_allowance' => 'employees.import.payroll',
        'other_allowances' => 'employees.import.payroll',
        'bank' => 'employees.import.bank',
        'iban' => 'employees.import.bank',
        'account_name' => 'employees.import.bank',
    ];

    public const IMPORT_MIME_TYPES = [
        'text/csv',
        'text/plain',
        'application/csv',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public const TEMPLATE_HEADERS = [
        'employee_no',
        'name',
        'work_email',
        'personal_email',
        'phone',
        'phone_home_country',
        'date_of_birth',
        'place_of_birth',
        'marital_status',
        'spouse_name',
        'spouse_birthdate',
        'dependent_children_count',
        'address',
        'nearest_airport',
        'cv_source',
        'emergency_contact',
        'emergency_phone',
        'emergency_contact_home_country',
        'emergency_phone_home_country',
        'passport_number',
        'emirates_id',
        'labor_card_number',
        'branch',
        'department',
        'position',
        'manager_employee_no',
        'gender',
        'religion',
        'nationality',
        'contract_type',
        'start_date',
        'end_date',
        'probation_end_date',
        'labor_contract_id',
        'basic_salary',
        'housing_allowance',
        'transport_allowance',
        'other_allowances',
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
    private ?array $managerByNoMap = null;

    /**
     * @var array<string, int>|null
     */
    private ?array $managerByNameMap = null;

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
    public function validateRows(array $rows, array $mapping): array
    {
        $this->primeLookups();

        $validRows = [];
        $errors = [];

        $duplicateNos = [];
        $existingNos = $this->existingEmployeeNos();

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $shaped = $this->shapeRow($row, $mapping);

            $rules = [
                'employee_no' => ['required', 'string', 'max:50'],
                'name' => ['required', 'string', 'max:200'],
                'start_date' => ['required', 'date'],
                'contract_type' => ['required', 'in:limited,unlimited,part_time,contract'],
                'work_email' => ['nullable', 'email', 'max:200'],
                'personal_email' => ['nullable', 'email', 'max:200'],
                'date_of_birth' => ['nullable', 'date'],
                'spouse_birthdate' => ['nullable', 'date'],
                'end_date' => ['nullable', 'date'],
                'probation_end_date' => ['nullable', 'date'],
                'marital_status' => ['nullable', 'in:single,married,divorced,widowed'],
                'status' => ['nullable', 'in:active,inactive,on_leave,terminated'],
                'basic_salary' => ['nullable', 'numeric', 'min:0'],
                'housing_allowance' => ['nullable', 'numeric', 'min:0'],
                'transport_allowance' => ['nullable', 'numeric', 'min:0'],
                'other_allowances' => ['nullable', 'numeric', 'min:0'],
                'dependent_children_count' => ['nullable', 'integer', 'min:0', 'max:999'],
            ];

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
     * Persist employees + their primary contract / bank account.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{created: int, failed: array<int, array{row: int, message: string}>}
     */
    public function execute(array $rows): array
    {
        $this->primeLookups();
        $existingNos = $this->existingEmployeeNos();

        $created = 0;
        $failed = [];

        DB::transaction(function () use ($rows, $existingNos, &$created, &$failed) {
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
                        'employee_no' => $no,
                        'name' => $row['name'] ?? null,
                        'work_email' => $row['work_email'] ?? null,
                        'personal_email' => $row['personal_email'] ?? null,
                        'phone' => $row['phone'] ?? null,
                        'phone_home_country' => $row['phone_home_country'] ?? null,
                        'date_of_birth' => $row['date_of_birth'] ?? null,
                        'place_of_birth' => $row['place_of_birth'] ?? null,
                        'marital_status' => $row['marital_status'] ?: null,
                        'spouse_name' => $row['spouse_name'] ?? null,
                        'spouse_birthdate' => $row['spouse_birthdate'] ?? null,
                        'dependent_children_count' => isset($row['dependent_children_count']) && $row['dependent_children_count'] !== ''
                            ? (int) $row['dependent_children_count']
                            : null,
                        'address' => $row['address'] ?? null,
                        'nearest_airport' => $row['nearest_airport'] ?? null,
                        'cv_source' => $row['cv_source'] ?? null,
                        'emergency_contact' => $row['emergency_contact'] ?? null,
                        'emergency_phone' => $row['emergency_phone'] ?? null,
                        'emergency_contact_home_country' => $row['emergency_contact_home_country'] ?? null,
                        'emergency_phone_home_country' => $row['emergency_phone_home_country'] ?? null,
                        'passport_number' => $row['passport_number'] ?? null,
                        'emirates_id' => $row['emirates_id'] ?? null,
                        'labor_card_number' => $row['labor_card_number'] ?? null,
                        'branch_id' => $resolved['branch_id'] ?? null,
                        'department_id' => $resolved['department_id'] ?? null,
                        'position_id' => $resolved['position_id'] ?? null,
                        'manager_id' => $resolved['manager_id'] ?? null,
                        'gender_id' => $resolved['gender_id'] ?? null,
                        'religion_id' => $resolved['religion_id'] ?? null,
                        'nationality_id' => $resolved['nationality_id'] ?? null,
                        'status' => $row['status'] ?: 'active',
                    ]);

                    EmployeeContract::query()->create([
                        'company_id' => $this->companyId,
                        'employee_id' => $employee->id,
                        'contract_type' => $row['contract_type'],
                        'start_date' => $row['start_date'],
                        'end_date' => $row['end_date'] ?? null,
                        'probation_end_date' => $row['probation_end_date'] ?? null,
                        'labor_contract_id' => $row['labor_contract_id'] ?? null,
                        'basic_salary' => $row['basic_salary'] !== '' ? $row['basic_salary'] : null,
                        'housing_allowance' => $row['housing_allowance'] !== '' ? $row['housing_allowance'] : null,
                        'transport_allowance' => $row['transport_allowance'] !== '' ? $row['transport_allowance'] : null,
                        'other_allowances' => $row['other_allowances'] !== '' ? $row['other_allowances'] : null,
                        'status' => 'active',
                    ]);

                    $bankId = $resolved['bank_id'] ?? null;
                    $iban = $row['iban'] ?? null;
                    $accountName = $row['account_name'] ?? null;

                    if ($bankId || $iban || $accountName) {
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

                    if (isset($this->managerByNoMap)) {
                        $this->managerByNoMap[strtolower($no)] = $employee->id;
                    }
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
    private function shapeRow(array $row, array $mapping): array
    {
        $shaped = [];

        foreach (array_keys(self::FIELD_ALIASES) as $field) {
            $header = $mapping[$field] ?? null;
            $value = $header !== null ? ($row[$header] ?? null) : null;

            if (is_string($value)) {
                $value = trim($value);

                if ($value === '') {
                    $value = null;
                }
            }

            $shaped[$field] = $value;
        }

        $shaped = $this->normaliseDateFields($shaped, [
            'date_of_birth',
            'spouse_birthdate',
            'start_date',
            'end_date',
            'probation_end_date',
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

        if (isset($shaped['dependent_children_count']) && $shaped['dependent_children_count'] !== null) {
            $shaped['dependent_children_count'] = is_numeric($shaped['dependent_children_count'])
                ? (int) $shaped['dependent_children_count']
                : null;
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

        if (! empty($row['manager_employee_no'])) {
            $no = strtolower((string) $row['manager_employee_no']);

            if (! isset($this->managerByNoMap[$no])) {
                $unresolved['manager_employee_no'] = sprintf('Manager with employee no "%s" not found.', $row['manager_employee_no']);
            }
        } elseif (! empty($row['manager'])) {
            $name = self::normalize((string) $row['manager']);

            if (! isset($this->managerByNameMap[$name])) {
                $unresolved['manager'] = sprintf('Manager "%s" not found.', $row['manager']);
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
            'manager_id' => null,
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

        if (! empty($row['manager_employee_no'])) {
            $resolved['manager_id'] = $this->managerByNoMap[strtolower((string) $row['manager_employee_no'])] ?? null;
        } elseif (! empty($row['manager'])) {
            $resolved['manager_id'] = $this->managerByNameMap[self::normalize((string) $row['manager'])] ?? null;
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

        $this->managerByNoMap = Employee::query()
            ->where('company_id', $this->companyId)
            ->pluck('id', 'employee_no')
            ->mapWithKeys(fn ($id, $no) => [strtolower((string) $no) => (int) $id])
            ->all();

        $this->managerByNameMap = Employee::query()
            ->where('company_id', $this->companyId)
            ->get(['id', 'name'])
            ->mapWithKeys(fn ($e) => [self::normalize((string) $e->name) => (int) $e->id])
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
}
