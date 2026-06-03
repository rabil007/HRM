<?php

namespace App\Http\Controllers\Settings;

use App\Enums\EmailTemplateCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreEmailTemplateRequest;
use App\Http\Requests\Settings\UpdateEmailTemplateRequest;
use App\Models\EmailTemplate;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class EmailTemplateController extends Controller
{
    public function index(): Response
    {
        $user = request()->user();

        if (! $user?->can('settings.integrations.email-templates.view')) {
            abort(403);
        }

        $templates = EmailTemplate::query()
            ->orderBy('category')
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get()
            ->map(fn (EmailTemplate $template) => $template->toBrowseArray());

        return Inertia::render('settings/email-templates', [
            'templates' => $templates,
            'categories' => collect(EmailTemplateCategory::cases())
                ->map(fn (EmailTemplateCategory $category) => [
                    'value' => $category->value,
                    'label' => $category->label(),
                ])
                ->values(),
            'can' => [
                'create' => $user->can('settings.integrations.email-templates.create'),
                'update' => $user->can('settings.integrations.email-templates.update'),
                'delete' => $user->can('settings.integrations.email-templates.delete'),
            ],
        ]);
    }

    public function store(StoreEmailTemplateRequest $request): RedirectResponse
    {
        $template = EmailTemplate::query()->create([
            ...$request->validated(),
            'sort_order' => $request->integer('sort_order'),
        ]);

        if ($template->is_default) {
            $template->markAsDefaultForCategory();
        }

        return back()->with('success', 'Email template created.');
    }

    public function update(UpdateEmailTemplateRequest $request, EmailTemplate $emailTemplate): RedirectResponse
    {
        $emailTemplate->update([
            ...$request->validated(),
            'sort_order' => $request->integer('sort_order'),
        ]);

        if ($emailTemplate->is_default) {
            $emailTemplate->markAsDefaultForCategory();
        }

        return back()->with('success', 'Email template updated.');
    }

    public function destroy(EmailTemplate $emailTemplate): RedirectResponse
    {
        if (! request()->user()?->can('settings.integrations.email-templates.delete')) {
            abort(403);
        }

        if ($emailTemplate->is_default) {
            return back()->withErrors([
                'template' => 'Set another default template in this category before deleting this one.',
            ]);
        }

        $emailTemplate->delete();

        return back()->with('success', 'Email template deleted.');
    }
}
