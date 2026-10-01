<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\DocumentExpiryNotification\StoreDocumentExpiryNotificationRuleRequest;
use App\Http\Requests\Organization\DocumentExpiryNotification\UpdateDocumentExpiryNotificationRuleRequest;
use App\Models\DocumentExpiryNotificationRule;
use App\Models\DocumentType;
use App\Support\EmployeeDocuments\DocumentExpiryNotification\DocumentExpiryNotificationRulePresenter;
use App\Support\EmployeeDocuments\DocumentExpiryNotification\ResolveDocumentExpiryNotificationRecipients;
use App\Support\EmployeeDocuments\DocumentExpiryNotification\UpsertDocumentExpiryNotificationRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Spatie\Activitylog\Models\Activity;

class DocumentExpiryNotificationRuleController extends Controller
{
    public function index(
        Request $request,
        DocumentExpiryNotificationRulePresenter $presenter,
        ResolveDocumentExpiryNotificationRecipients $resolveRecipients,
    ): InertiaResponse {
        abort_unless($request->user()?->can('documents.notification-routing.view'), 403);

        $companyId = (int) $request->attributes->get('current_company_id');
        $documentTypeId = $request->integer('document_type_id') ?: null;

        $rules = DocumentExpiryNotificationRule::query()
            ->where('company_id', $companyId)
            ->with([
                'documentTypes:id,title',
                'toRecipients.user:id,name,email',
                'ccRecipients.user:id,name,email',
            ])
            ->orderByDesc('enabled')
            ->orderBy('name')
            ->get()
            ->map(fn (DocumentExpiryNotificationRule $rule): array => $presenter->present($rule))
            ->values()
            ->all();

        $documentTypes = DocumentType::query()
            ->where('is_active', true)
            ->orderBy('title')
            ->get(['id', 'title'])
            ->map(fn (DocumentType $type): array => [
                'id' => (int) $type->id,
                'title' => (string) $type->title,
            ])
            ->values()
            ->all();

        $companyUsers = $resolveRecipients->eligibleUsersForCompany($companyId)
            ->map(fn ($user): array => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
            ])
            ->values()
            ->all();

        $highlightDocumentType = null;

        if ($documentTypeId !== null) {
            $highlightDocumentType = DocumentType::query()
                ->whereKey($documentTypeId)
                ->first(['id', 'title']);
        }

        return Inertia::render('organization/documents/configuration/notification-routing', [
            'rules' => $rules,
            'document_types' => $documentTypes,
            'company_users' => $companyUsers,
            'highlight_document_type_id' => $highlightDocumentType?->id,
            'highlight_document_type_title' => $highlightDocumentType?->title,
            'can' => [
                'view' => true,
                'update' => $request->user()?->can('documents.notification-routing.update') ?? false,
            ],
        ]);
    }

    public function store(
        StoreDocumentExpiryNotificationRuleRequest $request,
        UpsertDocumentExpiryNotificationRule $upsert,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        $rule = new DocumentExpiryNotificationRule;

        $upsert->handle(
            $rule,
            $companyId,
            (string) $request->validated('name'),
            $request->boolean('enabled'),
            $request->boolean('all_document_types'),
            $request->documentTypeIds(),
            $request->toUserIds(),
            $request->toEmails(),
            $request->ccUserIds(),
            $request->ccEmails(),
            $request->user(),
            isCreate: true,
        );

        return back()->with('success', 'Notification routing rule created.');
    }

    public function update(
        UpdateDocumentExpiryNotificationRuleRequest $request,
        DocumentExpiryNotificationRule $rule,
        UpsertDocumentExpiryNotificationRule $upsert,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $this->assertRuleInCompany($rule, $companyId);

        $upsert->handle(
            $rule,
            $companyId,
            (string) $request->validated('name'),
            $request->boolean('enabled'),
            $request->boolean('all_document_types'),
            $request->documentTypeIds(),
            $request->toUserIds(),
            $request->toEmails(),
            $request->ccUserIds(),
            $request->ccEmails(),
            $request->user(),
            isCreate: false,
        );

        return back()->with('success', 'Notification routing rule updated.');
    }

    public function toggle(
        Request $request,
        DocumentExpiryNotificationRule $rule,
    ): RedirectResponse {
        abort_unless($request->user()?->can('documents.notification-routing.update'), 403);

        $companyId = (int) $request->attributes->get('current_company_id');
        $this->assertRuleInCompany($rule, $companyId);

        $enabled = ! $rule->enabled;

        if ($enabled) {
            $hasTo = $rule->toRecipients()->exists();

            if (! $hasTo) {
                return back()->with('error', 'Add at least one TO recipient before enabling this rule.');
            }
        }

        $rule->update([
            'enabled' => $enabled,
            'updated_by' => $request->user()->id,
        ]);

        activity()
            ->useLog('documents')
            ->causedBy($request->user())
            ->event($enabled ? 'document_expiry_notification_rule_enabled' : 'document_expiry_notification_rule_disabled')
            ->performedOn($rule)
            ->withProperties([
                'company_id' => $companyId,
                'enabled' => $enabled,
            ])
            ->tap(function (Activity $activity) use ($companyId): void {
                $activity->company_id = $companyId;
            })
            ->log($enabled
                ? 'Employee document expiry notification rule enabled'
                : 'Employee document expiry notification rule disabled');

        return back()->with('success', $enabled ? 'Rule enabled.' : 'Rule disabled.');
    }

    public function destroy(
        Request $request,
        DocumentExpiryNotificationRule $rule,
    ): RedirectResponse {
        abort_unless($request->user()?->can('documents.notification-routing.update'), 403);

        $companyId = (int) $request->attributes->get('current_company_id');
        $this->assertRuleInCompany($rule, $companyId);

        $ruleName = (string) $rule->name;

        activity()
            ->useLog('documents')
            ->causedBy($request->user())
            ->event('document_expiry_notification_rule_deleted')
            ->performedOn($rule)
            ->withProperties([
                'company_id' => $companyId,
                'name' => $ruleName,
            ])
            ->tap(function (Activity $activity) use ($companyId): void {
                $activity->company_id = $companyId;
            })
            ->log('Employee document expiry notification rule deleted');

        $rule->delete();

        return back()->with('success', 'Notification routing rule deleted.');
    }

    private function assertRuleInCompany(DocumentExpiryNotificationRule $rule, int $companyId): void
    {
        abort_unless((int) $rule->company_id === $companyId, 404);
    }
}
