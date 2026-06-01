<?php

namespace App\Support\Employees;

use App\Models\Bank;
use App\Models\Branch;
use App\Models\CompanyVisaType;
use App\Models\Country;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\Gender;
use App\Models\Position;
use App\Models\Rank;
use App\Models\Religion;
use App\Models\User;
use App\Models\VisaType;
use Illuminate\Support\Collection;

final class EmployeeFormOptions
{
    /**
     * Shared lookup props for the employee directory and profile pages.
     *
     * @return array{
     *     branches: Collection,
     *     departments: Collection,
     *     positions: Collection,
     *     managers: Collection,
     *     users: Collection,
     *     countries: Collection,
     *     religions: Collection,
     *     genders: Collection,
     *     visa_types: Collection,
     *     company_visa_types: Collection,
     *     banks: Collection
     * }
     */
    public static function for(int $companyId, ?Employee $excludeManager = null): array
    {
        return [
            'branches' => self::branchesForDirectory($companyId),
            'departments' => self::departmentsForDirectory($companyId),
            'positions' => self::positionsForDirectory($companyId),
            'managers' => self::managers($companyId, $excludeManager, includeCompanyId: true),
            'users' => self::users($companyId),
            'countries' => self::countries(),
            'religions' => self::religions(),
            'genders' => self::genders(),
            'visa_types' => self::visaTypes(),
            'company_visa_types' => self::companyVisaTypes(),
            'banks' => self::banks(),
        ];
    }

    /**
     * Nested options payload for the onboarding employee create form.
     *
     * @return array{
     *     branches: Collection,
     *     departments: Collection,
     *     positions: Collection,
     *     managers: Collection,
     *     countries: Collection,
     *     religions: Collection,
     *     genders: Collection,
     *     visa_types: Collection,
     *     company_visa_types: Collection,
     *     banks: Collection,
     *     ranks: Collection,
     *     document_types: Collection
     * }
     */
    public static function forCreate(int $companyId): array
    {
        return [
            'branches' => self::branchesForCreate($companyId),
            'departments' => self::departmentsForCreate($companyId),
            'positions' => self::positionsForCreate($companyId),
            'managers' => self::managers($companyId, excludeManager: null, includeCompanyId: false),
            'countries' => self::countries(),
            'religions' => self::religions(),
            'genders' => self::genders(),
            'visa_types' => self::visaTypes(),
            'company_visa_types' => self::companyVisaTypes(),
            'banks' => self::banks(),
            'ranks' => self::activeRanks(),
            'document_types' => self::documentTypes(),
        ];
    }

    /**
     * Additional profile-only lookup props (ranks, document types).
     *
     * @param  list<int>  $ensureRankIds
     * @return array{
     *     ranks: Collection,
     *     document_types: Collection
     * }
     */
    public static function forProfile(int $companyId, Employee $employee, array $ensureRankIds = []): array
    {
        return [
            'ranks' => self::ranksForProfile($employee, $ensureRankIds),
            'document_types' => self::documentTypes(),
        ];
    }

    private static function branchesForDirectory(int $companyId)
    {
        return once(fn () => Branch::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'company_id', 'name']));
    }

    private static function branchesForCreate(int $companyId)
    {
        return once(fn () => Branch::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'name']));
    }

    private static function departmentsForDirectory(int $companyId)
    {
        return once(fn () => Department::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'company_id', 'name']));
    }

    private static function departmentsForCreate(int $companyId)
    {
        return once(fn () => Department::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'name']));
    }

    private static function positionsForDirectory(int $companyId)
    {
        return once(fn () => Position::query()
            ->where('company_id', $companyId)
            ->orderBy('title')
            ->get(['id', 'company_id', 'department_id', 'title']));
    }

    private static function positionsForCreate(int $companyId)
    {
        return once(fn () => Position::query()
            ->where('company_id', $companyId)
            ->orderBy('title')
            ->get(['id', 'department_id', 'title']));
    }

    private static function managers(int $companyId, ?Employee $excludeManager, bool $includeCompanyId)
    {
        $columns = $includeCompanyId
            ? ['id', 'company_id', 'name', 'employee_no']
            : ['id', 'name', 'employee_no'];

        return Employee::query()
            ->where('company_id', $companyId)
            ->when($excludeManager, fn ($query) => $query->where('id', '!=', $excludeManager->id))
            ->orderBy('name')
            ->get($columns);
    }

    private static function users(int $companyId)
    {
        return once(fn () => User::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'company_id', 'name', 'email']));
    }

    private static function countries()
    {
        return once(fn () => Country::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'dial_code']));
    }

    private static function religions()
    {
        return once(fn () => Religion::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']));
    }

    private static function genders()
    {
        return once(fn () => Gender::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']));
    }

    private static function visaTypes()
    {
        return once(fn () => VisaType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']));
    }

    private static function companyVisaTypes()
    {
        return once(fn () => CompanyVisaType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']));
    }

    private static function banks()
    {
        return once(fn () => Bank::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']));
    }

    private static function activeRanks()
    {
        return once(fn () => Rank::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']));
    }

    private static function documentTypes()
    {
        return once(fn () => DocumentType::query()
            ->where('is_active', true)
            ->orderBy('title')
            ->get(['id', 'title']));
    }

    /**
     * @param  list<int>  $ensureRankIds
     */
    private static function ranksForProfile(Employee $employee, array $ensureRankIds)
    {
        return Rank::query()
            ->where(function ($query) use ($employee, $ensureRankIds): void {
                $query->where('is_active', true);

                $ensureIds = collect([$employee->rank_id, ...$ensureRankIds])
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                if ($ensureIds !== []) {
                    $query->orWhereIn('id', $ensureIds);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
