<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Imports\EmployeesImport;
use App\Models\EmployeeProfileTemplate;
use App\Support\Employees\EmployeeImportTemplateExporter;
use App\Support\Employees\Services\EmployeeImportOrchestrator;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmployeeImportController extends Controller
{
    public function __construct(
        private readonly EmployeeImportOrchestrator $importOrchestrator,
        private readonly EmployeeImportTemplateExporter $templateExporter,
    ) {}

    public function importTemplate(Request $request)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $headers = $this->importOrchestrator->importColumnsForRequest($request);
        $template = $this->importOrchestrator->resolveProfileTemplateForImport($request);

        // #region agent log
        @file_put_contents(base_path('.cursor/debug-351a82.log'), json_encode(['sessionId' => '351a82', 'runId' => 'post-fix', 'hypothesisId' => 'A', 'location' => 'EmployeeImportController.php:importTemplate', 'message' => 'import template download headers', 'data' => ['company_id' => $companyId, 'query_template_id' => $request->query('template_id'), 'resolved_template_id' => $template?->id, 'resolved_template_name' => $template?->name, 'headers' => $headers, 'has_company_visa_type' => in_array('company_visa_type', $headers, true), 'has_sponsor' => in_array('sponsor', $headers, true), 'header_index_company_visa_type' => array_search('company_visa_type', $headers, true), 'header_index_sponsor' => array_search('sponsor', $headers, true)], 'timestamp' => (int) (microtime(true) * 1000)])."\n", FILE_APPEND);
        // #endregion

        $result = $this->templateExporter->export($companyId, $headers, $template);

        return response()->download($result['path'], $result['filename'], [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
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
        $canUpdateEmployees = $request->user()?->can('employees.update') ?? false;
        $result = $importer->validateRows($rows, $mapping, $template, $canUpdateEmployees);

        return response()->json([
            'headers' => $headers,
            'mapping' => $mapping,
            'rows' => $result['rows'],
            'errors' => $result['errors'],
            'summary' => $result['summary'],
            'row_actions' => $result['row_actions'],
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
        $canUpdateEmployees = $request->user()?->can('employees.update') ?? false;
        $validation = $importer->validateRows($rows, $mapping, $template, $canUpdateEmployees);

        $invalidRowNumbers = collect($validation['errors'])->pluck('row')->unique()->all();
        $importable = collect($validation['rows'])
            ->reject(fn ($_, $i) => in_array($i + 2, $invalidRowNumbers, true))
            ->values()
            ->all();

        $templateId = $validated['employee_profile_template_id'] ?? null;

        $result = $importer->execute(
            $importable,
            $templateId !== null ? (int) $templateId : null,
            $canUpdateEmployees,
        );

        $importedCount = $result['created'] + $result['updated'];

        if ($importedCount === 0) {
            $message = count($validation['errors']) > 0
                ? 'No employees were imported. Fix the validation errors shown in the preview and try again.'
                : 'No employees were imported. The file contained no valid rows.';

            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $message,
                    'errors' => $validation['errors'],
                    'created' => 0,
                    'updated' => 0,
                    'skipped' => $invalidRowNumbers,
                    'failed' => $result['failed'],
                ], 422);
            }

            return back()->withErrors(['file' => $message]);
        }

        $message = sprintf(
            'Created %d employee%s, updated %d employee%s. %d row%s skipped.',
            $result['created'],
            $result['created'] === 1 ? '' : 's',
            $result['updated'],
            $result['updated'] === 1 ? '' : 's',
            count($invalidRowNumbers) + count($result['failed']),
            count($invalidRowNumbers) + count($result['failed']) === 1 ? '' : 's',
        );

        if ($request->wantsJson()) {
            return response()->json([
                'created' => $result['created'],
                'updated' => $result['updated'],
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
