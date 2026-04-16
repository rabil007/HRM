<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\OnboardingRecord;
use App\Models\OnboardingTemplate;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class OnboardingRecordController extends Controller
{
    public function index()
    {
        $companyId = (int) request()->attributes->get('current_company_id');

        $records = OnboardingRecord::query()
            ->with([
                'employee:id,first_name,last_name,employee_no',
                'template:id,name',
            ])
            ->where('company_id', $companyId)
            ->latest('id')
            ->paginate(20)
            ->through(fn (OnboardingRecord $record) => [
                'id' => $record->id,
                'employee' => $record->employee ? [
                    'id' => $record->employee->id,
                    'employee_no' => $record->employee->employee_no,
                    'name' => trim("{$record->employee->first_name} {$record->employee->last_name}"),
                ] : null,
                'template' => $record->template ? [
                    'id' => $record->template->id,
                    'name' => $record->template->name,
                ] : null,
                'status' => $record->status,
                'stage' => $record->stage,
                'start_date' => $record->start_date,
                'completed_at' => $record->completed_at,
                'created_at' => $record->created_at,
            ]);

        return Inertia::render('onboarding/records', [
            'records' => $records,
        ]);
    }

    public function advance(OnboardingRecord $record)
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        abort_unless((int) $record->company_id === $companyId, 404);

        $template = $record->template_id
            ? OnboardingTemplate::query()->where('company_id', $companyId)->whereKey($record->template_id)->first()
            : null;

        if (! $template) {
            return redirect()->route('onboarding.records.index')->with('error', 'Template not found for this record.');
        }

        $tasks = (array) ($template->tasks ?? []);
        $stages = (array) ($tasks['stages'] ?? []);

        $currentIndex = collect($stages)->search(fn ($s) => ($s['key'] ?? null) === $record->stage);
        if ($currentIndex === false) {
            $currentIndex = 0;
        }

        $next = $stages[$currentIndex + 1] ?? null;
        if (! $next) {
            return redirect()->route('onboarding.records.index')->with('info', 'No next stage available.');
        }

        if (! $this->isStageComplete($record, $template)) {
            return redirect()->route('onboarding.records.index')->with('error', 'Complete required fields before advancing.');
        }

        $nextKey = (string) ($next['key'] ?? 'draft');

        $record->update([
            'stage' => $nextKey,
            'status' => $nextKey === 'done' ? 'completed' : 'in_progress',
            'completed_at' => $nextKey === 'done' ? now()->toDateString() : null,
        ]);

        return redirect()->route('onboarding.records.index')->with('success', 'Stage advanced successfully.');
    }

    private function isStageComplete(OnboardingRecord $record, OnboardingTemplate $template): bool
    {
        $employee = Employee::query()
            ->with('currentContract')
            ->whereKey($record->employee_id)
            ->first();

        if (! $employee) {
            return false;
        }

        $tasks = (array) ($template->tasks ?? []);
        $stages = (array) ($tasks['stages'] ?? []);
        $modules = (array) ($tasks['modules'] ?? []);

        $stage = collect($stages)->first(fn ($s) => ($s['key'] ?? null) === $record->stage);
        if (! $stage) {
            return true;
        }

        $stageModules = (array) ($stage['modules'] ?? []);

        foreach ($stageModules as $moduleKey) {
            $module = (array) ($modules[$moduleKey] ?? []);
            $requiredFields = (array) ($module['required_fields'] ?? []);
            $requiredDocs = (array) ($module['required_docs'] ?? []);
            $storeTable = (string) (($module['store']['table'] ?? '') ?: '');

            if ($storeTable === 'employees') {
                foreach ($requiredFields as $field) {
                    $value = $employee->{$field} ?? null;
                    if ($value === null || $value === '') {
                        return false;
                    }
                }
            }

            if ($storeTable === 'employee_contracts') {
                $contract = $employee->currentContract;
                if (! $contract) {
                    return false;
                }

                foreach ($requiredFields as $field) {
                    $value = $contract->{$field} ?? null;
                    if ($value === null || $value === '') {
                        return false;
                    }
                }
            }

            if ($storeTable === 'employee_documents') {
                foreach ($requiredDocs as $req) {
                    $type = (string) ($req['type'] ?? '');
                    $min = (int) ($req['min'] ?? 1);
                    $askIssueDate = (bool) ($req['ask_issue_date'] ?? false);
                    $askExpiryDate = (bool) ($req['ask_expiry_date'] ?? false);
                    $askDocumentNumber = (bool) ($req['ask_document_number'] ?? false);
                    if (! $type) {
                        continue;
                    }

                    $query = DB::table('employee_documents')
                        ->where('company_id', $record->company_id)
                        ->where('employee_id', $record->employee_id)
                        ->where('document_type', $type)
                        ->whereNotNull('file_path');

                    if ($askIssueDate) {
                        $query->whereNotNull('issue_date');
                    }

                    if ($askExpiryDate) {
                        $query->whereNotNull('expiry_date');
                    }

                    if ($askDocumentNumber) {
                        $query->whereNotNull('document_number')->where('document_number', '!=', '');
                    }

                    $count = $query->count();

                    if ($count < $min) {
                        return false;
                    }
                }
            }
        }

        return true;
    }
}
