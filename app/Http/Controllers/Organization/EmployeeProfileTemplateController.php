<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\EmployeeProfileTemplate\StoreEmployeeProfileTemplateRequest;
use App\Http\Requests\Organization\EmployeeProfileTemplate\UpdateEmployeeProfileTemplateRequest;
use App\Models\EmployeeProfileTemplate;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateFieldRegistry;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateResolver;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class EmployeeProfileTemplateController extends Controller
{
    public function index()
    {
        $companyId = (int) request()->attributes->get('current_company_id');

        $templates = EmployeeProfileTemplate::query()
            ->where('company_id', $companyId)
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'company_id', 'name', 'description', 'is_active', 'created_at']);

        return Inertia::render('organization/templates/employee-profile/index', [
            'templates' => $templates,
        ]);
    }

    public function create()
    {
        return Inertia::render('organization/templates/employee-profile/form', [
            'template' => null,
            'registry' => $this->registryPayload(),
            'defaultConfiguration' => EmployeeProfileTemplateFieldRegistry::defaultConfiguration(),
        ]);
    }

    public function edit(EmployeeProfileTemplate $employeeProfileTemplate)
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        abort_unless((int) $employeeProfileTemplate->company_id === $companyId, 404);

        return Inertia::render('organization/templates/employee-profile/form', [
            'template' => [
                'id' => $employeeProfileTemplate->id,
                'name' => $employeeProfileTemplate->name,
                'description' => $employeeProfileTemplate->description,
                'is_active' => $employeeProfileTemplate->is_active,
                'configuration_json' => EmployeeProfileTemplateResolver::normalizeForStorage(
                    is_array($employeeProfileTemplate->configuration_json)
                        ? $employeeProfileTemplate->configuration_json
                        : [],
                ),
            ],
            'registry' => $this->registryPayload(),
            'defaultConfiguration' => EmployeeProfileTemplateFieldRegistry::defaultConfiguration(),
        ]);
    }

    public function store(StoreEmployeeProfileTemplateRequest $request): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $data = $request->validated();

        $configuration = json_decode((string) $data['configuration_json'], true);
        if (! is_array($configuration)) {
            return back()->withErrors(['configuration_json' => 'Invalid JSON.']);
        }

        EmployeeProfileTemplate::query()->create([
            'company_id' => $companyId,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'configuration_json' => EmployeeProfileTemplateResolver::normalizeForStorage($configuration),
        ]);

        return redirect()
            ->route('organization.employee-profile-templates.index')
            ->with('success', 'Employee profile template created successfully.');
    }

    public function update(UpdateEmployeeProfileTemplateRequest $request, EmployeeProfileTemplate $employeeProfileTemplate): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $employeeProfileTemplate->company_id === $companyId, 404);

        $data = $request->validated();
        $configuration = json_decode((string) $data['configuration_json'], true);
        if (! is_array($configuration)) {
            return back()->withErrors(['configuration_json' => 'Invalid JSON.']);
        }

        $employeeProfileTemplate->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? $employeeProfileTemplate->is_active),
            'configuration_json' => EmployeeProfileTemplateResolver::normalizeForStorage($configuration),
        ]);

        return redirect()
            ->route('organization.employee-profile-templates.index')
            ->with('success', 'Employee profile template updated successfully.');
    }

    public function destroy(EmployeeProfileTemplate $employeeProfileTemplate): RedirectResponse
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        abort_unless((int) $employeeProfileTemplate->company_id === $companyId, 404);

        $employeeProfileTemplate->delete();

        return redirect()
            ->route('organization.employee-profile-templates.index')
            ->with('success', 'Employee profile template deleted successfully.');
    }

    /**
     * @return array{
     *     tab_order: list<string>,
     *     tab_labels: array<string, string>,
     *     tab_to_tables: array<string, list<string>>,
     *     fields_by_table: array<string, array<string, string>>
     * }
     */
    private function registryPayload(): array
    {
        return [
            'tab_order' => EmployeeProfileTemplateFieldRegistry::TAB_ORDER,
            'tab_labels' => EmployeeProfileTemplateFieldRegistry::tabLabels(),
            'tab_to_tables' => EmployeeProfileTemplateFieldRegistry::tabToTables(),
            'fields_by_table' => EmployeeProfileTemplateFieldRegistry::fieldsByTable(),
        ];
    }
}
