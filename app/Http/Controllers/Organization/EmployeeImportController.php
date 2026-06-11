<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Imports\EmployeesImport;
use App\Models\EmployeeProfileTemplate;
use App\Support\Employees\Services\EmployeeImportOrchestrator;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmployeeImportController extends Controller
{
    public function __construct(
        private readonly EmployeeImportOrchestrator $importOrchestrator,
    ) {}

    public function importTemplate(Request $request)
    {
        $headers = $this->importOrchestrator->importColumnsForRequest($request);

        $sampleRow = array_fill(0, count($headers), '');
        $sampleMap = [
            'employee_no' => 'EMP-001',
            'name' => 'John Doe',
            'work_email' => 'john.doe@example.com',
            'phone' => '+971500000000',
            'date_of_birth' => '1990-01-15',
            'hire_date' => now()->format('Y-m-d'),
            'marital_status' => 'single',
            'contract_type' => 'unlimited',
            'start_date' => now()->format('Y-m-d'),
            'status' => 'active',
        ];

        foreach ($headers as $i => $header) {
            if (isset($sampleMap[$header])) {
                $sampleRow[$i] = $sampleMap[$header];
            }
        }

        $callback = function () use ($headers, $sampleRow) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            fputcsv($out, $sampleRow);
            fclose($out);
        };

        return response()->streamDownload($callback, 'employees-import-template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function importPage()
    {
        $companyId = (int) request()->attributes->get('current_company_id');

        $templates = EmployeeProfileTemplate::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'description'])
            ->map(fn (EmployeeProfileTemplate $template) => [
                'id' => $template->id,
                'name' => $template->name,
                'description' => $template->description,
            ]);

        $defaultTemplateId = null;

        $importPageRequest = request();

        return Inertia::render('organization/employee-import', [
            'template_url' => route('organization.employees.import.template'),
            'preview_url' => route('organization.employees.import.preview'),
            'import_url' => route('organization.employees.import'),
            'field_options' => $this->importOrchestrator->importFieldOptions($importPageRequest),
            'max_rows' => EmployeesImport::MAX_ROWS,
            'templates' => $templates,
            'default_template_id' => $defaultTemplateId,
        ]);
    }

    public function importPreview(Request $request)
    {
        $validated = $this->importOrchestrator->validateImportRequest($request);

        $companyId = (int) $request->attributes->get('current_company_id');
        $importer = new EmployeesImport($companyId, (int) $request->user()->id);

        $file = $request->file('file');
        $headers = $importer->readHeaders($file);
        $mapping = $importer->sanitizeMapping($headers, $validated['mapping'] ?? null, $this->importOrchestrator->allowedImportFields($request));
        $rows = $this->importOrchestrator->readImportRows($importer, $file);
        $template = $this->importOrchestrator->resolveProfileTemplateForImport($request);
        $result = $importer->validateRows($rows, $mapping, $template);

        return response()->json([
            'headers' => $headers,
            'mapping' => $mapping,
            'rows' => $result['rows'],
            'errors' => $result['errors'],
            'summary' => $result['summary'],
            'field_options' => $this->importOrchestrator->importFieldOptions($request),
            'max_rows' => EmployeesImport::MAX_ROWS,
            'token' => null,
        ]);
    }

    public function import(Request $request)
    {
        $validated = $this->importOrchestrator->validateImportRequest($request);

        $companyId = (int) $request->attributes->get('current_company_id');
        $importer = new EmployeesImport($companyId, (int) $request->user()->id);

        $file = $request->file('file');
        $headers = $importer->readHeaders($file);
        $mapping = $importer->sanitizeMapping($headers, $validated['mapping'] ?? null, $this->importOrchestrator->allowedImportFields($request));
        $rows = $this->importOrchestrator->readImportRows($importer, $file);
        $template = $this->importOrchestrator->resolveProfileTemplateForImport($request);
        $validation = $importer->validateRows($rows, $mapping, $template);

        $invalidRowNumbers = collect($validation['errors'])->pluck('row')->unique()->all();
        $importable = collect($validation['rows'])
            ->reject(fn ($_, $i) => in_array($i + 2, $invalidRowNumbers, true))
            ->values()
            ->all();

        $templateId = $validated['employee_profile_template_id'] ?? null;

        $result = $importer->execute($importable, $templateId !== null ? (int) $templateId : null);

        if ($result['created'] === 0) {
            $message = count($validation['errors']) > 0
                ? 'No employees were imported. Fix the validation errors shown in the preview and try again.'
                : 'No employees were imported. The file contained no valid rows.';

            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $message,
                    'errors' => $validation['errors'],
                    'created' => 0,
                    'skipped' => $invalidRowNumbers,
                    'failed' => $result['failed'],
                ], 422);
            }

            return back()->withErrors(['file' => $message]);
        }

        $message = sprintf(
            'Imported %d employee%s. %d row%s skipped.',
            $result['created'],
            $result['created'] === 1 ? '' : 's',
            count($invalidRowNumbers) + count($result['failed']),
            count($invalidRowNumbers) + count($result['failed']) === 1 ? '' : 's',
        );

        if ($request->wantsJson()) {
            return response()->json([
                'created' => $result['created'],
                'skipped' => $invalidRowNumbers,
                'failed' => $result['failed'],
                'errors' => $validation['errors'],
                'message' => $message,
            ]);
        }

        return redirect()
            ->route('organization.employees')
            ->with('success', $message);
    }
}
