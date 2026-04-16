<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\StoreOnboardingTemplateRequest;
use App\Http\Requests\Onboarding\UpdateOnboardingTemplateRequest;
use App\Models\OnboardingTemplate;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class OnboardingTemplateController extends Controller
{
    public function index()
    {
        $companyId = (int) request()->attributes->get('current_company_id');

        $templates = OnboardingTemplate::query()
            ->where('company_id', $companyId)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'company_id', 'name', 'description', 'tasks', 'is_default', 'created_at']);

        return Inertia::render('onboarding/templates', [
            'templates' => $templates,
        ]);
    }

    public function create()
    {
        return Inertia::render('onboarding/templates/create');
    }

    public function edit(OnboardingTemplate $template)
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        abort_unless((int) $template->company_id === $companyId, 404);

        return Inertia::render('onboarding/templates/edit', [
            'template' => $template,
        ]);
    }

    public function store(StoreOnboardingTemplateRequest $request)
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        $data = $request->validated();
        $tasks = json_decode((string) $data['tasks_json'], true);
        if (! is_array($tasks)) {
            return back()->withErrors(['tasks_json' => 'Invalid JSON.'])->with('error', 'Invalid template configuration.');
        }

        $data['tasks'] = $tasks;
        unset($data['tasks_json']);
        $data['company_id'] = $companyId;
        $data['is_default'] = (bool) ($data['is_default'] ?? false);

        DB::transaction(function () use ($companyId, $data) {
            if ($data['is_default']) {
                OnboardingTemplate::query()
                    ->where('company_id', $companyId)
                    ->update(['is_default' => false]);
            }

            OnboardingTemplate::query()->create($data);
        });

        return redirect()->route('onboarding.templates.index')->with('success', 'Template created successfully.');
    }

    public function update(UpdateOnboardingTemplateRequest $request, OnboardingTemplate $template)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $template->company_id === $companyId, 404);

        $data = $request->validated();
        $tasks = json_decode((string) $data['tasks_json'], true);
        if (! is_array($tasks)) {
            return back()->withErrors(['tasks_json' => 'Invalid JSON.'])->with('error', 'Invalid template configuration.');
        }

        $data['tasks'] = $tasks;
        unset($data['tasks_json']);
        $data['is_default'] = (bool) ($data['is_default'] ?? $template->is_default);

        DB::transaction(function () use ($companyId, $template, $data) {
            if ($data['is_default']) {
                OnboardingTemplate::query()
                    ->where('company_id', $companyId)
                    ->where('id', '!=', $template->id)
                    ->update(['is_default' => false]);
            }

            $template->update($data);
        });

        return redirect()->route('onboarding.templates.index')->with('success', 'Template updated successfully.');
    }

    public function destroy(OnboardingTemplate $template)
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        abort_unless((int) $template->company_id === $companyId, 404);

        if ($template->records()->exists()) {
            return redirect()->route('onboarding.templates.index')->with('error', 'Template is already in use.');
        }

        $template->delete();

        return redirect()->route('onboarding.templates.index')->with('success', 'Template deleted successfully.');
    }
}
