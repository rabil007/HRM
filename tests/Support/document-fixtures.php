<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\DocumentRequirement;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Position;
use App\Models\Project;
use App\Models\Rank;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;

function fakeEmployeeFileDisks(): void
{
    Storage::fake('local');
    Storage::fake('public');
}

function makeDocumentFixtures(): array
{
    $country = Country::query()->firstOrCreate(
        ['code' => 'DT1'],
        ['name' => 'Doc Test Land', 'dial_code' => '+900', 'is_active' => true],
    );

    $currency = Currency::query()->firstOrCreate(
        ['code' => 'DT1'],
        ['name' => 'Doc Test Currency', 'symbol' => 'D$', 'is_active' => true],
    );

    $company = Company::query()->create([
        'name' => 'DocCo',
        'slug' => 'docco-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $branch = Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'HQ',
        'code' => 'HQ',
        'status' => 'active',
        'is_headquarters' => true,
    ]);

    $employee = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'employee_no' => 'DOC001',
        'name' => 'Test Employee',
        'status' => 'active',
    ]);

    $passportType = DocumentType::query()->firstOrCreate(
        ['title' => 'Passport Copy'],
        ['is_active' => true],
    );

    $visaType = DocumentType::query()->firstOrCreate(
        ['title' => 'Visa'],
        ['is_active' => true],
    );

    return compact('company', 'branch', 'employee', 'passportType', 'visaType');
}

/**
 * @param  list<int>  $departmentIds
 * @param  list<int>  $positionIds
 * @param  list<int>  $rankIds
 * @param  list<int>  $projectIds
 */
function makeDocumentRequirement(
    int $companyId,
    int $documentTypeId,
    bool $requiredForAll = false,
    array $departmentIds = [],
    array $positionIds = [],
    array $rankIds = [],
    bool $isActive = true,
    array $projectIds = [],
): DocumentRequirement {
    $requirement = DocumentRequirement::query()->create([
        'company_id' => $companyId,
        'document_type_id' => $documentTypeId,
        'required_for_all' => $requiredForAll,
        'is_active' => $isActive,
    ]);

    $requirement->departments()->sync($departmentIds);
    $requirement->positions()->sync($positionIds);
    $requirement->ranks()->sync($rankIds);
    $requirement->projects()->sync($projectIds);

    return $requirement->fresh(['departments', 'positions', 'ranks', 'projects']) ?? $requirement;
}

/**
 * @return array{
 *     crew: Department,
 *     marine: Department,
 *     seafarer: Position,
 *     captain: Rank,
 *     chiefEngineer: Rank,
 *     adnoc: Project,
 *     aramco: Project,
 *     otherProject: Project
 * }
 */
function makeDocumentRequirementMatchScopes(int $companyId): array
{
    $suffix = uniqid();

    $crew = Department::query()->create([
        'company_id' => $companyId,
        'name' => 'Crew',
        'code' => 'CRW-'.$suffix,
        'status' => 'active',
    ]);
    $marine = Department::query()->create([
        'company_id' => $companyId,
        'name' => 'Marine',
        'code' => 'MAR-'.$suffix,
        'status' => 'active',
    ]);
    $seafarer = Position::query()->create([
        'company_id' => $companyId,
        'title' => 'Seafarer',
        'status' => 'active',
    ]);
    $captain = Rank::query()->create([
        'name' => 'Captain '.$suffix,
        'is_active' => true,
    ]);
    $chiefEngineer = Rank::query()->create([
        'name' => 'Chief Engineer '.$suffix,
        'is_active' => true,
    ]);
    $adnoc = Project::query()->create([
        'title' => 'ADNOC '.$suffix,
        'is_active' => true,
    ]);
    $aramco = Project::query()->create([
        'title' => 'ARAMCO '.$suffix,
        'is_active' => true,
    ]);
    $otherProject = Project::query()->create([
        'title' => 'Other Project '.$suffix,
        'is_active' => true,
    ]);

    return compact(
        'crew',
        'marine',
        'seafarer',
        'captain',
        'chiefEngineer',
        'adnoc',
        'aramco',
        'otherProject',
    );
}

function minimalPdfBytes(): string
{
    $pdf = new Fpdi;
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', '', 12);
    $pdf->Cell(0, 10, 'Test document');

    return $pdf->Output('S');
}

function createEmployeePdfDocument(
    int $companyId,
    int $employeeId,
    int $documentTypeId,
    string $relativePath,
    string $filename,
): EmployeeDocument {
    Storage::disk('public')->put($relativePath, minimalPdfBytes());

    return EmployeeDocument::query()->create([
        'company_id' => $companyId,
        'employee_id' => $employeeId,
        'document_type_id' => $documentTypeId,
        'type' => 'other',
        'document_type' => (string) $documentTypeId,
        'file_path' => $relativePath,
        'original_filename' => $filename,
        'mime_type' => 'application/pdf',
        'status' => 'valid',
    ]);
}

function makeUnmappedEmployeeDocument(
    int $companyId,
    int $employeeId,
    ?string $legacyType,
    string $relativePath = 'employee-documents/test/unmapped.pdf',
): EmployeeDocument {
    return EmployeeDocument::query()->create([
        'company_id' => $companyId,
        'employee_id' => $employeeId,
        'document_type_id' => null,
        'type' => 'other',
        'document_type' => $legacyType,
        'file_path' => $relativePath,
        'original_filename' => 'unmapped.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'valid',
    ]);
}
