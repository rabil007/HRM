<?php

namespace App\Http\Controllers\Settings;

use App\Enums\EmailTemplateCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PreviewEmailTemplateRequest;
use App\Http\Requests\Settings\StoreEmailTemplateRequest;
use App\Http\Requests\Settings\UpdateEmailTemplateRequest;
use App\Models\EmailTemplate;
use App\Support\Email\BuiltInEmailTemplates;
use App\Support\Email\EmailTemplatePreview;
use App\Support\Platform\PlatformAuthorization;
use App\Support\Settings\ApplicationTimezone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class EmailTemplateController extends Controller
{
    public function index(): Response
    {
        $user = request()->user();

        if (! PlatformAuthorization::canView($user)) {
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
                'create' => PlatformAuthorization::canManage($user),
                'update' => PlatformAuthorization::canManage($user),
                'delete' => PlatformAuthorization::canManage($user),
            ],
            'expiry_alert_template_slug' => config('documents.expiry_alert_template_slug'),
            'company_expiry_alert_template_slug' => config('documents.company_expiry_alert_template_slug'),
            'scheduler_timezone' => ApplicationTimezone::identifier(),
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
        $validated = $this->payloadForRuntimeControls($emailTemplate, $request->validated());

        $emailTemplate->update([
            ...$validated,
            'sort_order' => (int) ($validated['sort_order'] ?? $emailTemplate->sort_order),
        ]);

        if ($emailTemplate->is_default && BuiltInEmailTemplates::uiControls($emailTemplate->slug)['is_default']) {
            $emailTemplate->markAsDefaultForCategory();
        }

        return back()->with('success', 'Email template updated.');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function payloadForRuntimeControls(EmailTemplate $emailTemplate, array $validated): array
    {
        $controls = BuiltInEmailTemplates::uiControls($emailTemplate->slug);
        $preserved = [
            'to_preset' => 'to_preset',
            'cc_preset' => 'cc_preset',
            'dispatch_at' => 'dispatch_at',
            'subject' => 'subject',
            'body' => 'body_html',
            'enabled' => 'enabled',
            'is_default' => 'is_default',
        ];

        foreach ($preserved as $control => $attribute) {
            if (! ($controls[$control] ?? true)) {
                $validated[$attribute] = $emailTemplate->{$attribute};
            }
        }

        return $validated;
    }

    public function destroy(EmailTemplate $emailTemplate): RedirectResponse
    {
        if (! PlatformAuthorization::canManage(request()->user())) {
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

    public function preview(EmailTemplate $emailTemplate): HttpResponse
    {
        if (! PlatformAuthorization::canView(request()->user())) {
            abort(403);
        }

        $companyId = (int) request()->attributes->get('current_company_id');
        $preview = app(EmailTemplatePreview::class)->render(
            $emailTemplate,
            $companyId > 0 ? $companyId : null,
        );

        return response($preview['html'], 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Email-Subject' => $preview['subject'],
        ]);
    }

    public function previewDraft(PreviewEmailTemplateRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $validated = $request->validated();

        $preview = app(EmailTemplatePreview::class)->renderFromStrings(
            slug: $validated['slug'],
            subject: $validated['subject'],
            bodyHtml: $validated['body_html'],
            companyId: $companyId > 0 ? $companyId : null,
            includeCompanyFooter: (bool) ($validated['include_company_footer'] ?? true),
        );

        return response()->json($preview);
    }
}
