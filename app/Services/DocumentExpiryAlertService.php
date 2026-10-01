<?php

namespace App\Services;

use App\Enums\DocumentExpiryPushAlertStatus;
use App\Jobs\DeliverDocumentComplianceWebPushJob;
use App\Mail\DocumentExpiryAlertMail;
use App\Models\Company;
use App\Models\DocumentExpiryNotificationRule;
use App\Models\DocumentExpiryPushAlert;
use App\Models\EmailTemplate;
use App\Models\EmployeeDocument;
use App\Models\EmployeeDocumentExpiryAlert;
use App\Models\User;
use App\Support\EmployeeDocuments\DocumentExpiry;
use App\Support\EmployeeDocuments\DocumentExpiryNotification\ResolveDocumentExpiryNotificationRecipients;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;
use Throwable;

class DocumentExpiryAlertService
{
    public const LegacyDeliveryKey = 'legacy';

    public function __construct(
        private readonly ResolveDocumentExpiryNotificationRecipients $resolveRecipients,
    ) {}

    public function hasPendingDocuments(int $companyId): bool
    {
        $rules = $this->enabledRulesForCompany($companyId);

        if ($rules->isEmpty()) {
            return false;
        }

        foreach ($rules as $rule) {
            $recipients = $this->resolveRecipients->handle($rule, $companyId);

            if ($recipients['to_addresses'] === [] && $recipients['cc_addresses'] === []) {
                continue;
            }

            if ($recipients['to_addresses'] === [] && $recipients['cc_users']->isEmpty() && $recipients['cc_manual_emails'] === []) {
                continue;
            }

            $documents = $this->matchingDocumentsQuery($companyId, $rule)->get();

            if ($documents->isEmpty()) {
                continue;
            }

            if ($this->buildDeliveryBatches($companyId, $rule, $documents, $recipients) !== []) {
                return true;
            }
        }

        return $this->inWindowDocumentsQuery($companyId)->exists()
            && $this->companyHasPushEligibleRules($companyId, $rules);
    }

    public function sendForCompany(int $companyId): void
    {
        $company = Company::query()->findOrFail($companyId);

        if ($company->status !== 'active') {
            return;
        }

        if (! $this->alertTemplateEnabled()) {
            return;
        }

        $rules = $this->enabledRulesForCompany($companyId);
        $emailException = null;
        $pushDocumentsByUser = collect();

        foreach ($rules as $rule) {
            try {
                $this->sendForRule($company, $rule, $pushDocumentsByUser);
            } catch (Throwable $exception) {
                $emailException ??= $exception;
                $this->logFailure($company, $exception, $rule);
            }
        }

        try {
            $this->queuePushSummaries($company, $pushDocumentsByUser);
        } catch (Throwable $exception) {
            report($exception);
        }

        if ($emailException !== null) {
            throw $emailException;
        }
    }

    public function logFailure(Company $company, Throwable $exception, ?DocumentExpiryNotificationRule $rule = null): void
    {
        report($exception);

        activity()
            ->useLog('documents')
            ->event('expiry_alert_failed')
            ->performedOn($company)
            ->withProperties([
                'company_id' => $company->id,
                'notification_rule_id' => $rule?->id,
                'message' => $exception->getMessage(),
            ])
            ->tap(function (Activity $activity) use ($company): void {
                $activity->company_id = (int) $company->id;
            })
            ->log('Document expiry alert email failed');
    }

    public function alertWindowDays(): int
    {
        return (int) config('documents.expiry_alert_days');
    }

    /**
     * @deprecated Legacy template TO/CC presets are retired. Routing rules are the source of truth.
     *
     * @return array{recipient: string, cc: list<string>}
     */
    public function resolveRecipients(): array
    {
        return ['recipient' => '', 'cc' => []];
    }

    /**
     * Whether any enabled rule for the company currently resolves a TO recipient
     * (or a restricted CC cohort that will be promoted to TO).
     */
    public function companyHasDeliverableRules(int $companyId): bool
    {
        if (! $this->alertTemplateEnabled()) {
            return false;
        }

        foreach ($this->enabledRulesForCompany($companyId) as $rule) {
            $recipients = $this->resolveRecipients->handle($rule, $companyId);

            if (
                $recipients['to_addresses'] !== []
                || $recipients['cc_users']->isNotEmpty()
                || $recipients['cc_manual_emails'] !== []
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, Collection<int, EmployeeDocument>>  $pushDocumentsByUser
     */
    private function sendForRule(
        Company $company,
        DocumentExpiryNotificationRule $rule,
        Collection $pushDocumentsByUser,
    ): void {
        $recipients = $this->resolveRecipients->handle($rule, $company->id);

        if (
            $recipients['to_addresses'] === []
            && $recipients['cc_users']->isEmpty()
            && $recipients['cc_manual_emails'] === []
        ) {
            return;
        }

        $documents = $this->matchingDocumentsQuery($company->id, $rule)->get();
        $inWindowDocuments = $this->inWindowDocumentsQuery($company->id, $rule)->get();

        if ($documents->isEmpty() && $inWindowDocuments->isEmpty()) {
            return;
        }

        foreach ($recipients['to_users']->merge($recipients['cc_users']) as $user) {
            if (! $user instanceof User) {
                continue;
            }

            $visible = $inWindowDocuments->filter(function (EmployeeDocument $document) use ($user, $company): bool {
                $employeeId = (int) ($document->employee_id ?? $document->employee?->id ?? 0);

                return $employeeId > 0
                    && $this->resolveRecipients->userCanSeeEmployee($user, $company->id, $employeeId);
            });

            if ($visible->isEmpty()) {
                continue;
            }

            $existing = $pushDocumentsByUser->get($user->id, collect());
            $pushDocumentsByUser->put(
                $user->id,
                $existing->merge($visible)->unique('id')->values(),
            );
        }

        if ($documents->isEmpty()) {
            return;
        }

        $batches = $this->buildDeliveryBatches($company->id, $rule, $documents, $recipients);
        $batchException = null;

        foreach ($batches as $batch) {
            if ($batch['documents']->isEmpty() || $batch['to'] === []) {
                continue;
            }

            try {
                $this->sendEmailSummary($company, $batch['to'], $batch['cc'], $batch['documents']);
                $this->recordAlerts($batch['documents'], $company->id, $rule->id, $batch['delivery_key']);
                $this->logSuccess(
                    company: $company,
                    rule: $rule,
                    toRecipients: $batch['to'],
                    ccRecipients: $batch['cc'],
                    documents: $batch['documents'],
                );
            } catch (Throwable $exception) {
                $batchException ??= $exception;
            }
        }

        if ($batchException !== null) {
            throw $batchException;
        }
    }

    /**
     * Group recipients by identical visible-document sets so restricted CC users
     * receive their own filtered summary instead of being dropped from an
     * unrestricted email.
     *
     * @param  Collection<int, EmployeeDocument>  $documents
     * @param  array{
     *     to_addresses: list<string>,
     *     cc_addresses: list<string>,
     *     to_users: Collection<int, User>,
     *     cc_users: Collection<int, User>,
     *     to_manual_emails: list<string>,
     *     cc_manual_emails: list<string>
     * }  $recipients
     * @return list<array{to: list<string>, cc: list<string>, documents: Collection<int, EmployeeDocument>, delivery_key: string}>
     */
    private function buildDeliveryBatches(
        int $companyId,
        DocumentExpiryNotificationRule $rule,
        Collection $documents,
        array $recipients,
    ): array {
        if ($documents->isEmpty()) {
            return [];
        }

        $existingAlertKeys = $this->existingAlertLookup($rule->id, $documents);
        $participants = [];

        foreach ($recipients['to_users'] as $user) {
            $visible = $this->visibleDocumentsForUser($user, $companyId, $documents);

            if ($visible->isEmpty()) {
                continue;
            }

            $participants[] = [
                'role' => 'to',
                'email' => (string) $user->email,
                'document_ids' => $this->documentIdSignature($visible),
            ];
        }

        foreach ($recipients['to_manual_emails'] as $email) {
            $participants[] = [
                'role' => 'to',
                'email' => $email,
                'document_ids' => $this->documentIdSignature($documents),
            ];
        }

        foreach ($recipients['cc_users'] as $user) {
            $visible = $this->visibleDocumentsForUser($user, $companyId, $documents);

            if ($visible->isEmpty()) {
                continue;
            }

            $participants[] = [
                'role' => 'cc',
                'email' => (string) $user->email,
                'document_ids' => $this->documentIdSignature($visible),
            ];
        }

        foreach ($recipients['cc_manual_emails'] as $email) {
            $participants[] = [
                'role' => 'cc',
                'email' => $email,
                'document_ids' => $this->documentIdSignature($documents),
            ];
        }

        if ($participants === []) {
            return [];
        }

        $groups = [];

        foreach ($participants as $participant) {
            $signature = implode(',', $participant['document_ids']);
            $groups[$signature]['document_ids'] = $participant['document_ids'];
            $groups[$signature]['participants'][] = $participant;
        }

        $batches = [];

        foreach ($groups as $group) {
            $groupDocuments = $documents
                ->filter(fn (EmployeeDocument $document): bool => in_array((int) $document->id, $group['document_ids'], true))
                ->values();

            $to = [];
            $cc = [];

            foreach ($group['participants'] as $participant) {
                if ($participant['role'] === 'to') {
                    $to[] = $participant['email'];
                } else {
                    $cc[] = $participant['email'];
                }
            }

            // Restricted CC-only cohorts still need their filtered summary.
            if ($to === [] && $cc !== []) {
                $to = $cc;
                $cc = [];
            }

            if ($to === []) {
                continue;
            }

            $to = array_values(array_unique($to));
            $cc = $this->normalizeCcRecipients($to, $cc);
            $deliveryKey = $this->deliveryKey($to, $cc);

            $pendingDocuments = $groupDocuments
                ->filter(function (EmployeeDocument $document) use ($existingAlertKeys, $deliveryKey): bool {
                    $expiryDate = $document->expiry_date?->toDateString();

                    if ($expiryDate === null || $expiryDate === '') {
                        return false;
                    }

                    return ! $this->alreadyDelivered(
                        $existingAlertKeys,
                        (int) $document->id,
                        $expiryDate,
                        $deliveryKey,
                    );
                })
                ->values();

            if ($pendingDocuments->isEmpty()) {
                continue;
            }

            $batches[] = [
                'to' => $to,
                'cc' => $cc,
                'documents' => $pendingDocuments,
                'delivery_key' => $deliveryKey,
            ];
        }

        return $batches;
    }

    /**
     * @param  Collection<int, EmployeeDocument>  $documents
     * @return Collection<int, EmployeeDocument>
     */
    private function visibleDocumentsForUser(User $user, int $companyId, Collection $documents): Collection
    {
        return $documents
            ->filter(function (EmployeeDocument $document) use ($user, $companyId): bool {
                $employeeId = (int) ($document->employee_id ?? $document->employee?->id ?? 0);

                return $employeeId > 0
                    && $this->resolveRecipients->userCanSeeEmployee($user, $companyId, $employeeId);
            })
            ->values();
    }

    /**
     * @param  Collection<int, EmployeeDocument>  $documents
     * @return list<int>
     */
    private function documentIdSignature(Collection $documents): array
    {
        return $documents
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     */
    private function deliveryKey(array $to, array $cc): string
    {
        $normalize = static fn (array $emails): string => collect($emails)
            ->map(fn (string $email): string => strtolower(trim($email)))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->implode(',');

        return hash('sha256', 'to:'.$normalize($to).'|cc:'.$normalize($cc));
    }

    /**
     * @param  Collection<int, EmployeeDocument>  $documents
     * @return Collection<string, bool>
     */
    private function existingAlertLookup(int $ruleId, Collection $documents): Collection
    {
        $documentIds = $documents->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if ($documentIds === []) {
            return collect();
        }

        return EmployeeDocumentExpiryAlert::query()
            ->where('notification_rule_id', $ruleId)
            ->whereIn('employee_document_id', $documentIds)
            ->get(['employee_document_id', 'expiry_date_at_alert_time', 'delivery_key'])
            ->mapWithKeys(function (EmployeeDocumentExpiryAlert $alert): array {
                $expiry = $alert->expiry_date_at_alert_time?->toDateString() ?? '';

                return [
                    (int) $alert->employee_document_id.'|'.$expiry.'|'.(string) $alert->delivery_key => true,
                ];
            });
    }

    /**
     * @param  Collection<string, bool>  $existingAlertKeys
     */
    private function alreadyDelivered(
        Collection $existingAlertKeys,
        int $documentId,
        string $expiryDate,
        string $deliveryKey,
    ): bool {
        if ($existingAlertKeys->has($documentId.'|'.$expiryDate.'|'.self::LegacyDeliveryKey)) {
            return true;
        }

        return $existingAlertKeys->has($documentId.'|'.$expiryDate.'|'.$deliveryKey);
    }

    /**
     * @return Collection<int, DocumentExpiryNotificationRule>
     */
    private function enabledRulesForCompany(int $companyId): Collection
    {
        return DocumentExpiryNotificationRule::query()
            ->where('company_id', $companyId)
            ->where('enabled', true)
            ->with([
                'documentTypes:id,is_active',
                'toRecipients.user:id,name,email,status',
                'ccRecipients.user:id,name,email,status',
            ])
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, DocumentExpiryNotificationRule>  $rules
     */
    private function companyHasPushEligibleRules(int $companyId, Collection $rules): bool
    {
        foreach ($rules as $rule) {
            $recipients = $this->resolveRecipients->handle($rule, $companyId);

            if ($recipients['to_users']->isNotEmpty() || $recipients['cc_users']->isNotEmpty()) {
                return true;
            }
        }

        return false;
    }

    private function alertTemplateEnabled(): bool
    {
        return $this->resolveAlertTemplate() !== null;
    }

    private function resolveAlertTemplate(): ?EmailTemplate
    {
        $slug = (string) config('documents.expiry_alert_template_slug', 'document_expiry_alert');

        return EmailTemplate::query()
            ->where('slug', $slug)
            ->where('enabled', true)
            ->first();
    }

    /**
     * Matching in-window documents for a rule (ledger filtering happens per delivery cohort).
     *
     * @return Builder<EmployeeDocument>
     */
    private function matchingDocumentsQuery(int $companyId, DocumentExpiryNotificationRule $rule): Builder
    {
        return $this->inWindowDocumentsQuery($companyId, $rule);
    }

    /**
     * @return Builder<EmployeeDocument>
     */
    private function inWindowDocumentsQuery(int $companyId, ?DocumentExpiryNotificationRule $rule = null): Builder
    {
        return EmployeeDocument::query()
            ->forCompany($companyId)
            ->whereExpiringWithin($this->alertWindowDays())
            ->whereHas('documentType', function (Builder $query): void {
                $query->where('is_active', true);
            })
            ->when(
                $rule !== null && ! $rule->all_document_types,
                function (Builder $query) use ($rule): void {
                    $typeIds = $rule->documentTypes
                        ->filter(fn ($type): bool => (bool) ($type->is_active ?? true))
                        ->pluck('id')
                        ->map(fn ($id): int => (int) $id)
                        ->all();

                    if ($typeIds === []) {
                        $query->whereRaw('1 = 0');

                        return;
                    }

                    $query->whereIn('document_type_id', $typeIds);
                },
            )
            ->whereHas('employee', function ($employeeQuery) use ($companyId): void {
                $employeeQuery->where('company_id', $companyId)->active();
            })
            ->with(['employee:id,company_id,name,employee_no,department_id', 'documentType:id,title,is_active']);
    }

    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  Collection<int, EmployeeDocument>  $documents
     */
    private function sendEmailSummary(Company $company, array $to, array $cc, Collection $documents): void
    {
        $rows = $this->buildRows($documents);

        $mail = Mail::to($to);

        if ($cc !== []) {
            $mail->cc($cc);
        }

        $mail->send(new DocumentExpiryAlertMail(
            organizationName: (string) $company->name,
            rows: $rows,
            alertWindowDays: $this->alertWindowDays(),
            includeCompanyFooter: (bool) ($this->resolveAlertTemplate()?->include_company_footer ?? true),
            complianceUrl: route('organization.documents'),
        ));
    }

    /**
     * @param  Collection<int, Collection<int, EmployeeDocument>>  $pushDocumentsByUser
     */
    private function queuePushSummaries(Company $company, Collection $pushDocumentsByUser): void
    {
        if ($pushDocumentsByUser->isEmpty()) {
            return;
        }

        foreach ($pushDocumentsByUser as $userId => $documents) {
            $user = User::query()->find($userId);

            if (! $user instanceof User) {
                continue;
            }

            if ($user->pushSubscriptions()->doesntExist()) {
                continue;
            }

            $alertIds = $this->createPushAlertLedgerRows($company, $user, $documents);

            if ($alertIds === []) {
                continue;
            }

            DeliverDocumentComplianceWebPushJob::dispatch(
                $company->id,
                $user->id,
                $alertIds,
            )->afterCommit();
        }
    }

    /**
     * @param  Collection<int, EmployeeDocument>  $documents
     * @return list<int>
     */
    private function createPushAlertLedgerRows(Company $company, User $user, Collection $documents): array
    {
        $queuedAt = now();
        $alertIds = [];

        DB::transaction(function () use ($company, $user, $documents, $queuedAt, &$alertIds): void {
            foreach ($documents as $document) {
                $expiryDate = $document->expiry_date?->toDateString();

                if ($expiryDate === null || $expiryDate === '') {
                    continue;
                }

                $alert = DocumentExpiryPushAlert::query()->firstOrCreate(
                    [
                        'employee_document_id' => $document->id,
                        'user_id' => $user->id,
                        'expiry_date_at_alert_time' => $expiryDate,
                    ],
                    [
                        'company_id' => $company->id,
                        'status' => DocumentExpiryPushAlertStatus::Queued,
                        'queued_at' => $queuedAt,
                    ],
                );

                if (
                    $alert->wasRecentlyCreated
                    || $alert->status === DocumentExpiryPushAlertStatus::Queued
                ) {
                    $alertIds[] = (int) $alert->id;
                }
            }
        });

        return array_values(array_unique($alertIds));
    }

    /**
     * @param  Collection<int, EmployeeDocument>  $documents
     * @return list<array{employee_name: string, employee_id: string, document_name: string, expiry_date: string, days_remaining: int, folder_url: string}>
     */
    private function buildRows(Collection $documents): array
    {
        return $documents
            ->sortBy([
                fn (EmployeeDocument $document) => (string) $document->employee?->name,
                fn (EmployeeDocument $document) => $document->expiry_date?->toDateString() ?? '',
            ])
            ->values()
            ->map(function (EmployeeDocument $document): array {
                $expiryDate = $document->expiry_date?->toDateString() ?? '';
                $employee = $document->employee;

                return [
                    'employee_name' => (string) ($employee?->name ?? 'Unknown employee'),
                    'employee_id' => (string) ($employee?->employee_no ?: '—'),
                    'document_name' => $document->original_filename
                        ?? $document->title
                        ?? $document->document_type_label,
                    'expiry_date' => $expiryDate,
                    'days_remaining' => DocumentExpiry::remainingDays($document->expiry_date) ?? 0,
                    'folder_url' => $employee !== null
                        ? route('organization.documents.employee', $employee)
                        : '',
                ];
            })
            ->all();
    }

    /**
     * @param  Collection<int, EmployeeDocument>  $documents
     */
    private function recordAlerts(Collection $documents, int $companyId, int $ruleId, string $deliveryKey): void
    {
        $alertedAt = now();

        DB::transaction(function () use ($documents, $companyId, $ruleId, $deliveryKey, $alertedAt): void {
            foreach ($documents as $document) {
                $expiryDate = $document->expiry_date?->toDateString();

                if ($expiryDate === null || $expiryDate === '') {
                    continue;
                }

                EmployeeDocumentExpiryAlert::query()->firstOrCreate(
                    [
                        'notification_rule_id' => $ruleId,
                        'employee_document_id' => $document->id,
                        'expiry_date_at_alert_time' => $expiryDate,
                        'delivery_key' => $deliveryKey,
                    ],
                    [
                        'company_id' => $companyId,
                        'alerted_at' => $alertedAt,
                    ],
                );
            }
        });
    }

    /**
     * @param  list<string>  $toRecipients
     * @param  list<string>  $ccRecipients
     * @param  Collection<int, EmployeeDocument>  $documents
     */
    private function logSuccess(
        Company $company,
        DocumentExpiryNotificationRule $rule,
        array $toRecipients,
        array $ccRecipients,
        Collection $documents,
    ): void {
        activity()
            ->useLog('documents')
            ->event('expiry_alert_sent')
            ->performedOn($company)
            ->withProperties([
                'recipient' => $toRecipients[0] ?? '',
                'to' => $toRecipients,
                'cc' => $ccRecipients,
                'document_count' => $documents->count(),
                'company_id' => $company->id,
                'notification_rule_id' => $rule->id,
                'notification_rule_name' => $rule->name,
                'document_ids' => $documents->pluck('id')->values()->all(),
            ])
            ->tap(function (Activity $activity) use ($company): void {
                $activity->company_id = (int) $company->id;
            })
            ->log('Document expiry alert email sent');
    }

    /**
     * @param  list<string>  $toRecipients
     * @param  list<string>  $ccRecipients
     * @return list<string>
     */
    private function normalizeCcRecipients(array $toRecipients, array $ccRecipients): array
    {
        $toNormalized = collect($toRecipients)
            ->map(fn (string $email): string => strtolower(trim($email)))
            ->filter()
            ->all();

        return collect($ccRecipients)
            ->map(fn (string $email): string => trim($email))
            ->filter(fn (string $email): bool => $email !== '' && ! in_array(strtolower($email), $toNormalized, true))
            ->unique(fn (string $email): string => strtolower($email))
            ->values()
            ->all();
    }
}
