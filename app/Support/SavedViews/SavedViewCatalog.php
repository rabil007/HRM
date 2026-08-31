<?php

namespace App\Support\SavedViews;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewReliefRisk;
use App\Enums\CrewReliefStatus;
use App\Enums\CrewTourStatus;
use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Enums\SavedViewPage;
use App\Models\ApprovalLocation;
use App\Models\Branch;
use App\Models\Client;
use App\Models\CompanyVisaType;
use App\Models\Country;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\Gender;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\Project;
use App\Models\Rank;
use App\Models\SssaOption;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VisaType;
use App\Support\CrewMovements\CurrentCrewRequestFilters;
use App\Support\Employees\EmployeeCrewStatusFilter;
use App\Support\Employees\EmployeeDirectoryCompleteness;
use App\Support\Employees\EmployeeSmartSearchResolver;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

final class SavedViewCatalog
{
    /**
     * @return list<string>
     */
    public static function keys(SavedViewPage $page): array
    {
        return array_keys(self::definitions($page));
    }

    public static function userCanAccess(User $user, string $pageKey): bool
    {
        $page = SavedViewPage::tryFrom($pageKey);

        return $page !== null && $page->userCanAccess($user);
    }

    public static function queryHasExplicitFilters(Request $request, SavedViewPage $page): bool
    {
        foreach (self::keys($page) as $key) {
            if ($request->query->has($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, string>
     */
    public static function normalizeForSave(SavedViewPage $page, array $raw, int $companyId): array
    {
        if ($page === SavedViewPage::Employees) {
            $raw = self::migrateLegacyEmployeeFilters($raw, rejectInvalid: true);
        }

        $definitions = self::definitions($page);
        $unknown = array_diff(array_keys($raw), array_keys($definitions));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'filters' => 'Unsupported filter: '.implode(', ', array_values($unknown)).'.',
            ]);
        }

        $normalized = [];

        foreach ($definitions as $key => $definition) {
            if (! array_key_exists($key, $raw)) {
                continue;
            }

            $value = self::normalizeValue(
                $key,
                $definition,
                $raw[$key],
                $companyId,
                rejectInvalid: true,
            );

            if ($value === null) {
                continue;
            }

            $normalized[$key] = $value;
        }

        if ($normalized === []) {
            throw ValidationException::withMessages([
                'filters' => 'Save at least one supported filter.',
            ]);
        }

        return $normalized;
    }

    /**
     * @return array<string, string>
     */
    public static function forApply(SavedViewPage $page, mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        if ($page === SavedViewPage::Employees) {
            $raw = self::migrateLegacyEmployeeFilters($raw, rejectInvalid: false);
        }

        $normalized = [];

        foreach (self::definitions($page) as $key => $definition) {
            if (! array_key_exists($key, $raw)) {
                continue;
            }

            $value = self::normalizeValue(
                $key,
                $definition,
                $raw[$key],
                companyId: null,
                rejectInvalid: false,
            );

            if ($value === null) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function definitions(SavedViewPage $page): array
    {
        return match ($page) {
            SavedViewPage::Employees => [
                'search' => ['type' => 'search'],
                'status' => ['type' => 'enum', 'values' => EmployeeSmartSearchResolver::STATUSES],
                'branch_id' => ['type' => 'id', 'model' => Branch::class, 'company' => true],
                'department_id' => ['type' => 'id', 'model' => Department::class, 'company' => true],
                'position_id' => ['type' => 'id', 'model' => Position::class, 'company' => true],
                'manager_id' => ['type' => 'id', 'model' => Employee::class, 'company' => true],
                'gender_id' => ['type' => 'id', 'model' => Gender::class, 'company' => false],
                'nationality_id' => ['type' => 'id', 'model' => Country::class, 'company' => false],
                'visa_type_id' => ['type' => 'id', 'model' => VisaType::class, 'company' => false],
                'company_visa_type_id' => ['type' => 'id', 'model' => CompanyVisaType::class, 'company' => false],
                'rank_id' => ['type' => 'id', 'model' => Rank::class, 'company' => false],
                'project_id' => ['type' => 'id', 'model' => Project::class, 'company' => false],
                'approval_location_id' => ['type' => 'id', 'model' => ApprovalLocation::class, 'company' => false],
                'sssa_option_id' => ['type' => 'id', 'model' => SssaOption::class, 'company' => false],
                'crew_status' => ['type' => 'enum', 'values' => array_keys(EmployeeCrewStatusFilter::options())],
                'role_id' => ['type' => 'id', 'model' => Role::class, 'company' => true],
                EmployeeDirectoryCompleteness::MISSING_QUERY_KEY => ['type' => 'completeness'],
                EmployeeDirectoryCompleteness::PRESENT_QUERY_KEY => ['type' => 'completeness'],
            ],
            SavedViewPage::Documents => [
                'search' => ['type' => 'search'],
                'expiry' => ['type' => 'enum', 'values' => ['expired', 'expiring_30', 'expiring_15', 'expiring_7'], 'omit' => ['all']],
                'requirement_status' => ['type' => 'enum', 'values' => ['required', 'valid', 'expiring', 'expired', 'missing']],
                'department_id' => ['type' => 'id', 'model' => Department::class, 'company' => true],
                'document_type_id' => ['type' => 'id', 'model' => DocumentType::class, 'company' => false],
            ],
            SavedViewPage::Crew => [
                'search' => ['type' => 'search'],
                'phase' => ['type' => 'enum', 'values' => CrewPhaseCode::values()],
                'status' => ['type' => 'enum', 'values' => CrewAssignmentStatus::values()],
                'vessel_id' => ['type' => 'id', 'model' => Vessel::class, 'company' => true],
                'rank_id' => ['type' => 'id', 'model' => Rank::class, 'company' => false],
                'client_id' => ['type' => 'id', 'model' => Client::class, 'company' => false],
                'employee_id' => ['type' => 'id', 'model' => Employee::class, 'company' => true],
                'planned_join_from' => ['type' => 'date'],
                'planned_join_to' => ['type' => 'date'],
                'planned_signoff_from' => ['type' => 'date'],
                'planned_signoff_to' => ['type' => 'date'],
                'tour_status' => ['type' => 'enum', 'values' => array_map(fn (CrewTourStatus $status): string => $status->value, CrewTourStatus::filterable())],
                'relief_status' => ['type' => 'enum', 'values' => CrewReliefStatus::values()],
                'relief_risk' => ['type' => 'enum', 'values' => array_map(fn (CrewReliefRisk $status): string => $status->value, CrewReliefRisk::filterable())],
                'relief_not_ready' => ['type' => 'bool'],
                'signoff_within_14_no_relief' => ['type' => 'bool'],
                'movement_attention' => ['type' => 'bool'],
                'include_completed' => ['type' => 'bool'],
                'view' => ['type' => 'enum', 'values' => [CurrentCrewRequestFilters::VIEW_VESSEL], 'omit' => [CurrentCrewRequestFilters::VIEW_CREW]],
            ],
            SavedViewPage::Leave => [
                'search' => ['type' => 'search'],
                'status' => ['type' => 'enum', 'values' => ['pending', 'approved', 'rejected', 'cancelled']],
                'employee_id' => ['type' => 'id', 'model' => Employee::class, 'company' => true],
                'leave_type_id' => ['type' => 'id', 'model' => LeaveType::class, 'company' => true],
                'scope' => ['type' => 'enum', 'values' => ['awaiting_my_approval', 'assigned_to_me', 'all'], 'omit' => ['my']],
            ],
            SavedViewPage::Payroll => [
                'search' => ['type' => 'search'],
                'category' => ['type' => 'enum', 'values' => PayrollCategory::values()],
                'status' => ['type' => 'enum', 'values' => PayrollPeriodStatus::values()],
                'date_from' => ['type' => 'date'],
                'date_to' => ['type' => 'date'],
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function normalizeValue(
        string $key,
        array $definition,
        mixed $value,
        ?int $companyId,
        bool $rejectInvalid,
    ): ?string {
        if (is_array($value)) {
            self::failOrSkip($key, 'must be a scalar value.', $rejectInvalid);

            return null;
        }

        $type = (string) ($definition['type'] ?? '');

        return match ($type) {
            'search' => self::normalizeSearch($key, $value, $rejectInvalid),
            'enum' => self::normalizeEnum($key, $value, $definition, $rejectInvalid),
            'completeness' => self::normalizeCompleteness($key, $value, $rejectInvalid),
            'id' => self::normalizeId($key, $value, $definition, $companyId, $rejectInvalid),
            'bool' => self::normalizeBool($value),
            'date' => self::normalizeDate($key, $value, $rejectInvalid),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function migrateLegacyEmployeeFilters(array $raw, bool $rejectInvalid): array
    {
        if (! array_key_exists('emirates_id_presence', $raw)) {
            return $raw;
        }

        $legacy = strtolower(trim((string) $raw['emirates_id_presence']));
        unset($raw['emirates_id_presence']);

        if ($legacy === 'missing') {
            $raw[EmployeeDirectoryCompleteness::MISSING_QUERY_KEY] = EmployeeDirectoryCompleteness::toCsv([
                ...EmployeeDirectoryCompleteness::parse($raw[EmployeeDirectoryCompleteness::MISSING_QUERY_KEY] ?? '')['keys'],
                'emirates_id',
            ]);

            return $raw;
        }

        if ($legacy === 'present') {
            $raw[EmployeeDirectoryCompleteness::PRESENT_QUERY_KEY] = EmployeeDirectoryCompleteness::toCsv([
                ...EmployeeDirectoryCompleteness::parse($raw[EmployeeDirectoryCompleteness::PRESENT_QUERY_KEY] ?? '')['keys'],
                'emirates_id',
            ]);

            return $raw;
        }

        if ($legacy !== '') {
            self::failOrSkip('emirates_id_presence', 'is not a supported value.', $rejectInvalid);
        }

        return $raw;
    }

    private static function normalizeCompleteness(string $key, mixed $value, bool $rejectInvalid): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = EmployeeDirectoryCompleteness::parse($value);

        if (! $parsed['valid'] || $parsed['keys'] === []) {
            self::failOrSkip($key, 'is not a supported completeness concept.', $rejectInvalid);

            return null;
        }

        return EmployeeDirectoryCompleteness::toCsv($parsed['keys']);
    }

    private static function normalizeSearch(string $key, mixed $value, bool $rejectInvalid): ?string
    {
        if ($value === null) {
            return null;
        }

        $search = trim((string) $value);

        if ($search === '') {
            return null;
        }

        if (mb_strlen($search) > 100) {
            self::failOrSkip($key, 'may not be greater than 100 characters.', $rejectInvalid);

            return null;
        }

        return $search;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function normalizeEnum(string $key, mixed $value, array $definition, bool $rejectInvalid): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = trim((string) $value);
        /** @var list<string> $omit */
        $omit = $definition['omit'] ?? [];

        if (in_array($normalized, $omit, true)) {
            return null;
        }

        /** @var list<string> $values */
        $values = $definition['values'] ?? [];

        if (! in_array($normalized, $values, true)) {
            self::failOrSkip($key, 'is not a supported value.', $rejectInvalid);

            return null;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function normalizeId(
        string $key,
        mixed $value,
        array $definition,
        ?int $companyId,
        bool $rejectInvalid,
    ): ?string {
        if ($value === null || $value === '') {
            return null;
        }

        $asString = trim((string) $value);

        if (preg_match('/^[1-9][0-9]*$/', $asString) !== 1) {
            self::failOrSkip($key, 'must be a valid record id.', $rejectInvalid);

            return null;
        }

        $id = (int) $asString;

        if (! $rejectInvalid) {
            return (string) $id;
        }

        $model = $definition['model'] ?? null;

        if (! is_string($model) || ! is_subclass_of($model, Model::class)) {
            self::failOrSkip($key, 'must be a valid record id.', true);

            return null;
        }

        $scopedToCompany = (bool) ($definition['company'] ?? false);

        if ($scopedToCompany && ($companyId === null || $companyId < 1)) {
            self::failOrSkip($key, 'must belong to the active company.', true);

            return null;
        }

        /** @var Builder<Model> $query */
        $query = $model::query()->whereKey($id);

        if ($scopedToCompany) {
            $query->where('company_id', $companyId);
        }

        if (! $query->exists()) {
            self::failOrSkip(
                $key,
                $scopedToCompany
                    ? 'must belong to the active company.'
                    : 'must be a valid record id.',
                true,
            );

            return null;
        }

        return (string) $id;
    }

    private static function normalizeBool(mixed $value): ?string
    {
        if ($value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'on') {
            return '1';
        }

        return null;
    }

    private static function normalizeDate(string $key, mixed $value, bool $rejectInvalid): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = trim((string) $value);
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            self::failOrSkip($key, 'must be a valid date.', $rejectInvalid);

            return null;
        }

        return $date;
    }

    private static function failOrSkip(string $key, string $message, bool $rejectInvalid): void
    {
        if (! $rejectInvalid) {
            return;
        }

        throw ValidationException::withMessages([
            'filters.'.$key => 'The '.$key.' filter '.$message,
        ]);
    }
}
