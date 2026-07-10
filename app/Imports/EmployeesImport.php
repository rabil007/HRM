<?php

namespace App\Imports;

use App\Models\Branch;
use App\Models\Client;
use App\Models\CompanyVisaType;
use App\Models\Country;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeProfileTemplate;
use App\Models\Gender;
use App\Models\Position;
use App\Models\Project;
use App\Models\Rank;
use App\Models\Religion;
use App\Models\VisaType;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateRequestRules;
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
        'status' => ['status'],
        'branch' => ['branch', 'branch name'],
        'department' => ['department', 'department name', 'dept'],
        'position' => ['position', 'position title', 'job title', 'title'],
        'project' => ['project', 'project name', 'project title'],
        'client' => ['client', 'client name'],
        'gender' => ['gender'],
        'religion' => ['religion'],
        'nationality' => ['nationality', 'country'],
        'rank' => ['rank', 'rank name'],
        'visa_type' => ['visa_type', 'visa type'],
        'sponsor' => ['sponsor', 'sponsor name', 'company_visa_type', 'company visa type'],
    ];

    public const REQUIRED_FIELDS = ['employee_no', 'name'];

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
            'branch' => ['employees', 'branch_id'],
            'department' => ['employees', 'department_id'],
            'position' => ['employees', 'position_id'],
            'project' => ['employees', 'project_id'],
            'client' => ['employees', 'client_id'],
            'gender' => ['employees', 'gender_id'],
            'religion' => ['employees', 'religion_id'],
            'nationality' => ['employees', 'nationality_id'],
            'rank' => ['employees', 'rank_id'],
            'visa_type' => ['employees', 'visa_type_id'],
            'sponsor' => ['employees', 'company_visa_type_id'],
            'status' => ['employees', 'status'],
        ];
    }

    public const SENSITIVE_FIELD_PERMISSIONS = [
        'passport_number' => 'employees.identity.import',
        'emirates_id' => 'employees.identity.import',
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
        'branch',
        'department',
        'position',
        'project',
        'client',
        'gender',
        'religion',
        'nationality',
        'rank',
        'visa_type',
        'sponsor',
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
    private ?array $projectMap = null;

    /**
     * @var array<string, int>|null
     */
    private ?array $clientMap = null;

    /**
     * @var array<string, int>|null
     */
    private ?array $companyVisaTypeMap = null;

    /**
     * @var array<string, int>|null
     */
    private ?array $rankMap = null;

    /**
     * @var array<string, int>|null
     */
    private ?array $visaTypeMap = null;

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
     * @return array{rows: array<int, array<string, mixed>>, errors: array<int, array{row: int, field: string, message: string}>, summary: array{total: int, valid: int, invalid: int, create: int, update: int}, row_actions: array<int, string>}
     */
    public function validateRows(
        array $rows,
        array $mapping,
        ?EmployeeProfileTemplate $template = null,
        bool $canUpdateEmployees = false,
    ): array {
        $this->primeLookups();

        $validRows = [];
        $errors = [];
        $rowActions = [];

        $duplicateNos = [];
        $existingEmployees = $this->existingEmployeesByNo();
        $createRules = $this->validationRulesForTemplate($template, $mapping);

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $shaped = $this->shapeRow($row, $mapping);
            $no = $shaped['employee_no'] ?? null;
            $action = $this->resolveRowAction($no, $existingEmployees);
            $rowActions[$rowNumber] = $action;

            $rules = $action === 'update'
                ? $this->validationRulesForUpdateRow($createRules, $shaped)
                : $createRules;

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

                if ($action === 'update' && ! $canUpdateEmployees) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'field' => 'employee_no',
                        'message' => 'You do not have permission to update existing employees.',
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

        $invalidRowNumbers = collect($errors)->pluck('row')->unique();
        $validRowNumbers = collect($rowActions)
            ->keys()
            ->reject(fn (int $rowNumber) => $invalidRowNumbers->contains($rowNumber));

        $createCount = $validRowNumbers
            ->filter(fn (int $rowNumber) => ($rowActions[$rowNumber] ?? 'create') === 'create')
            ->count();

        $updateCount = $validRowNumbers
            ->filter(fn (int $rowNumber) => ($rowActions[$rowNumber] ?? 'create') === 'update')
            ->count();

        return [
            'rows' => $validRows,
            'errors' => $errors,
            'row_actions' => $rowActions,
            'summary' => [
                'total' => count($validRows),
                'valid' => max(0, count($validRows) - $invalidRowNumbers->count()),
                'invalid' => $invalidRowNumbers->count(),
                'create' => $createCount,
                'update' => $updateCount,
            ],
        ];
    }

    /**
     * Persist employees from import rows.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{created: int, updated: int, failed: array<int, array{row: int, message: string}>}
     */
    public function execute(
        array $rows,
        ?int $onboardingTemplateId = null,
        bool $canUpdateEmployees = false,
    ): array {
        $this->primeLookups();
        $existingEmployees = $this->existingEmployeesByNo();

        $created = 0;
        $updated = 0;
        $failed = [];

        DB::transaction(function () use ($rows, $existingEmployees, $onboardingTemplateId, $canUpdateEmployees, &$created, &$updated, &$failed) {
            foreach ($rows as $index => $row) {
                $rowNumber = $index + 2;
                $no = (string) ($row['employee_no'] ?? '');

                if ($no === '') {
                    $failed[] = [
                        'row' => $rowNumber,
                        'message' => 'Missing employee number.',
                    ];

                    continue;
                }

                $action = $this->resolveRowAction($no, $existingEmployees);

                if ($action === 'update') {
                    if (! $canUpdateEmployees) {
                        $failed[] = [
                            'row' => $rowNumber,
                            'message' => 'You do not have permission to update existing employees.',
                        ];

                        continue;
                    }

                    $employee = $existingEmployees[strtolower($no)] ?? null;

                    if ($employee === null) {
                        $failed[] = [
                            'row' => $rowNumber,
                            'message' => 'Employee not found for update.',
                        ];

                        continue;
                    }

                    try {
                        $payload = $this->buildPartialUpdatePayload($row);

                        if ($payload !== []) {
                            $employee->update($payload);
                        }

                        $updated++;
                    } catch (\Throwable $e) {
                        $failed[] = [
                            'row' => $rowNumber,
                            'message' => $e->getMessage(),
                        ];
                    }

                    continue;
                }

                try {
                    $resolved = $this->resolveIdsForInsert($row);

                    Employee::create([
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
                        'branch_id' => $resolved['branch_id'] ?? null,
                        'department_id' => $resolved['department_id'] ?? null,
                        'position_id' => $resolved['position_id'] ?? null,
                        'project_id' => $resolved['project_id'] ?? null,
                        'client_id' => $resolved['client_id'] ?? null,
                        'gender_id' => $resolved['gender_id'] ?? null,
                        'religion_id' => $resolved['religion_id'] ?? null,
                        'nationality_id' => $resolved['nationality_id'] ?? null,
                        'rank_id' => $resolved['rank_id'] ?? null,
                        'visa_type_id' => $resolved['visa_type_id'] ?? null,
                        'company_visa_type_id' => $resolved['company_visa_type_id'] ?? null,
                        'status' => $row['status'] ?: 'active',
                    ]);

                    $existingEmployees[strtolower($no)] = Employee::query()
                        ->where('company_id', $this->companyId)
                        ->whereRaw('LOWER(employee_no) = ?', [strtolower($no)])
                        ->first();
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
            'updated' => $updated,
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
            'status' => ['nullable', 'in:active,inactive,on_leave,terminated'],
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

        return $rules;
    }

    /**
     * @param  array<string, list<string|object>>  $baseRules
     * @param  array<string, mixed>  $shaped
     * @return array<string, list<string|object>>
     */
    private function validationRulesForUpdateRow(array $baseRules, array $shaped): array
    {
        $rules = [
            'employee_no' => ['required', 'string', 'max:50'],
        ];

        foreach ($baseRules as $field => $fieldRules) {
            if ($field === 'employee_no' || ! $this->fieldHasValue($shaped, $field)) {
                continue;
            }

            if ($field === 'name') {
                $rules[$field] = ['string', 'max:200'];

                continue;
            }

            $rules[$field] = $fieldRules;
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $shaped
     */
    private function fieldHasValue(array $shaped, string $field): bool
    {
        if (! array_key_exists($field, $shaped)) {
            return false;
        }

        $value = $shaped[$field];

        return $value !== null && $value !== '';
    }

    /**
     * @param  array<string, Employee>  $existingEmployees
     */
    private function resolveRowAction(?string $employeeNo, array $existingEmployees): string
    {
        if ($employeeNo === null || $employeeNo === '') {
            return 'create';
        }

        return isset($existingEmployees[strtolower($employeeNo)]) ? 'update' : 'create';
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function buildPartialUpdatePayload(array $row): array
    {
        $payload = [];
        $resolved = $this->resolveIdsForInsert($row);

        $directFields = [
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
            'status',
        ];

        foreach ($directFields as $field) {
            if ($this->fieldHasValue($row, $field)) {
                $payload[$field] = $row[$field];
            }
        }

        foreach (['branch' => 'branch_id', 'department' => 'department_id', 'position' => 'position_id'] as $key => $field) {
            if ($this->fieldHasValue($row, $key)) {
                $payload[$field] = $resolved[$field];
            }
        }

        foreach (['gender' => 'gender_id', 'religion' => 'religion_id', 'nationality' => 'nationality_id', 'project' => 'project_id', 'client' => 'client_id', 'rank' => 'rank_id', 'visa_type' => 'visa_type_id', 'sponsor' => 'company_visa_type_id'] as $key => $field) {
            if ($this->fieldHasValue($row, $key)) {
                $payload[$field] = $resolved[$field];
            }
        }

        return $payload;
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
            } else {
                $value = self::coerceStringCell($value);
            }

            $shaped[$field] = $value;
        }

        $shaped = $this->normaliseDateFields($shaped, [
            'date_of_birth',
            'hire_date',
        ]);

        if (isset($shaped['marital_status']) && is_string($shaped['marital_status'])) {
            $shaped['marital_status'] = strtolower($shaped['marital_status']);
        }

        if (isset($shaped['status']) && is_string($shaped['status'])) {
            $shaped['status'] = strtolower($shaped['status']);
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

        foreach (['gender' => $this->genderMap, 'religion' => $this->religionMap, 'nationality' => $this->countryMap, 'project' => $this->projectMap, 'client' => $this->clientMap, 'rank' => $this->rankMap, 'visa_type' => $this->visaTypeMap, 'sponsor' => $this->companyVisaTypeMap] as $key => $map) {
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
            'project_id' => null,
            'client_id' => null,
            'gender_id' => null,
            'religion_id' => null,
            'nationality_id' => null,
            'rank_id' => null,
            'visa_type_id' => null,
            'company_visa_type_id' => null,
        ];

        foreach (['branch' => 'branch_id', 'department' => 'department_id', 'position' => 'position_id'] as $key => $field) {
            if (! empty($row[$key])) {
                $name = self::normalize((string) $row[$key]);
                $map = $key === 'branch' ? $this->branchMap : ($key === 'department' ? $this->departmentMap : $this->positionMap);
                $resolved[$field] = $map[$name] ?? null;
            }
        }

        foreach (['gender' => 'gender_id', 'religion' => 'religion_id', 'nationality' => 'nationality_id', 'project' => 'project_id', 'client' => 'client_id', 'rank' => 'rank_id', 'visa_type' => 'visa_type_id', 'sponsor' => 'company_visa_type_id'] as $key => $field) {
            if (! empty($row[$key])) {
                $name = self::normalize((string) $row[$key]);
                $map = match ($key) {
                    'gender' => $this->genderMap,
                    'religion' => $this->religionMap,
                    'nationality' => $this->countryMap,
                    'project' => $this->projectMap,
                    'client' => $this->clientMap,
                    'rank' => $this->rankMap,
                    'visa_type' => $this->visaTypeMap,
                    'sponsor' => $this->companyVisaTypeMap,
                    default => [],
                };
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

        $this->projectMap = Project::query()
            ->pluck('id', 'title')
            ->mapWithKeys(fn ($id, $title) => [self::normalize((string) $title) => (int) $id])
            ->all();

        $this->clientMap = Client::query()
            ->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [self::normalize((string) $name) => (int) $id])
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

        $this->companyVisaTypeMap = CompanyVisaType::query()
            ->where('is_active', true)
            ->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [self::normalize((string) $name) => (int) $id])
            ->all();

        $this->rankMap = Rank::query()
            ->where('is_active', true)
            ->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [self::normalize((string) $name) => (int) $id])
            ->all();

        $this->visaTypeMap = VisaType::query()
            ->where('is_active', true)
            ->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [self::normalize((string) $name) => (int) $id])
            ->all();

    }

    /**
     * @return array<string, Employee>
     */
    private function existingEmployeesByNo(): array
    {
        return Employee::query()
            ->where('company_id', $this->companyId)
            ->get()
            ->keyBy(fn (Employee $employee) => strtolower((string) $employee->employee_no))
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
