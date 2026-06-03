<?php

namespace App\Http\Controllers\Settings;

use App\Enums\WhatsAppTemplateCategory;
use App\Enums\WhatsAppTemplateHeaderType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreWhatsAppTemplateRequest;
use App\Http\Requests\Settings\UpdateWhatsAppTemplateRequest;
use App\Models\WhatsAppTemplate;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class WhatsAppTemplateController extends Controller
{
    public function index(): Response
    {
        $user = request()->user();

        if (! $user?->can('settings.integrations.whatsapp-templates.view')) {
            abort(403);
        }

        $templates = WhatsAppTemplate::query()
            ->orderBy('category')
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get()
            ->map(fn (WhatsAppTemplate $template) => $template->toBrowseArray());

        return Inertia::render('settings/whatsapp-templates', [
            'templates' => $templates,
            'categories' => collect(WhatsAppTemplateCategory::cases())
                ->map(fn (WhatsAppTemplateCategory $category) => [
                    'value' => $category->value,
                    'label' => $category->label(),
                ])
                ->values(),
            'header_types' => collect(WhatsAppTemplateHeaderType::cases())
                ->map(fn (WhatsAppTemplateHeaderType $type) => [
                    'value' => $type->value,
                    'label' => $type->label(),
                ])
                ->values(),
            'language_options' => [
                ['value' => 'en', 'label' => 'English (en)'],
                ['value' => 'en_US', 'label' => 'English US (en_US)'],
                ['value' => 'en_GB', 'label' => 'English UK (en_GB)'],
                ['value' => 'ar', 'label' => 'Arabic (ar)'],
            ],
            'can' => [
                'create' => $user->can('settings.integrations.whatsapp-templates.create'),
                'update' => $user->can('settings.integrations.whatsapp-templates.update'),
                'delete' => $user->can('settings.integrations.whatsapp-templates.delete'),
            ],
            'meta_template_manager_url' => config('whatsapp.meta_template_manager_url'),
        ]);
    }

    public function store(StoreWhatsAppTemplateRequest $request): RedirectResponse
    {
        $template = WhatsAppTemplate::query()->create([
            ...$request->validated(),
            'sort_order' => $request->integer('sort_order'),
        ]);

        if ($template->is_default) {
            $template->markAsDefaultForCategory();
        }

        return back()->with('success', 'WhatsApp template created.');
    }

    public function update(UpdateWhatsAppTemplateRequest $request, WhatsAppTemplate $whatsappTemplate): RedirectResponse
    {
        $whatsappTemplate->update([
            ...$request->validated(),
            'sort_order' => $request->integer('sort_order'),
        ]);

        if ($whatsappTemplate->is_default) {
            $whatsappTemplate->markAsDefaultForCategory();
        }

        return back()->with('success', 'WhatsApp template updated.');
    }

    public function destroy(WhatsAppTemplate $whatsappTemplate): RedirectResponse
    {
        if (! request()->user()?->can('settings.integrations.whatsapp-templates.delete')) {
            abort(403);
        }

        if ($whatsappTemplate->is_default) {
            return back()->withErrors([
                'template' => 'Set another default template in this category before deleting this one.',
            ]);
        }

        $whatsappTemplate->delete();

        return back()->with('success', 'WhatsApp template deleted.');
    }
}
