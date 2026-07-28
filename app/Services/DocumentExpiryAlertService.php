<?php

namespace App\Services;

use App\Enums\DocumentExpiryPushAlertStatus;
use App\Jobs\DeliverDocumentComplianceWebPushJob;
use App\Mail\DocumentExpiryAlertMail;
use App\Models\Company;
use App\Models\DocumentExpiryPushAlert;
use App\Models\EmailTemplate;
use App\Models\EmployeeDocument;
use App\Models\EmployeeDocumentExpiryAlert;
use App\Models\User;
use App\Support\Email\CommaSeparatedEmailList;
use App\Support\EmployeeDocuments\DocumentExpiry;
use App\Support\Notifications\ResolveTemplatePushRecipients;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;
use Throwable;

class DocumentExpiryAlertService
{
    public function __construct(
        private readonly ResolveTemplatePushRecipients $resolveTemplatePushRecipients,
    ) {}

    public function hasPendingDocuments(int $companyId): bool
    {
        if ($this->resolveRecipients()['recipient'] === '') {
            return false;
        }

        return $this->pendingDocumentsQuery($companyId)->exists()
            || $this->inWindowDocumentsQuery($companyId)->exists();
    }

    public function sendForCompany(int $companyId): void
    {
        $company = Company::query()->findOrFail($companyId);

        if ($company->status !== 'active') {
            return;
        }

        $recipients = $this->resolveRecipients();

        if ($recipients['recipient'] === '') {
            return;
        }

        $emailDocuments = $this->pendingDocumentsQuery($companyId)->get();
        $pushDocuments = $this->inWindowDocumentsQuery($companyId)->get();

        if ($emailDocuments->isEmpty() && $pushDocuments->isEmpty()) {
            return;
        }

        $emailException = null;

        if ($emailDocuments->isNotEmpty()) {
            try {
                $this->sendEmailSummary($company, $recipients, $emailDocuments);
                $this->recordAlerts($emailDocuments, $companyId);
                $this->logSuccess(
                    company: $company,
                    recipient: $recipients['recipient'],
                    ccRecipients: $this->normalizeCcRecipients($recipients['recipient'], $recipients['cc']),
                    documents: $emailDocuments,
                );
            } catch (Throwable $exception) {
                $emailException = $exception;
                $this->logFailure($company, $exception);
            }
        }

        try {
            $this->queuePushSummaries($company, $pushDocuments);
        } catch (Throwable $exception) {
            report($exception);
        }

        if ($emailException !== null) {
            throw $emailException;
        }
    }

    public function logFailure(Company $company, Throwable $exception): void
    {
        report($exception);

        activity()
            ->useLog('documents')
            ->event('expiry_alert_failed')
            ->performedOn($company)
            ->withProperties([
                'company_id' => $company->id,
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
     * @return array{recipient: string, cc: list<string>}
     */
    public function resolveRecipients(): array
    {
        $template = $this->resolveAlertTemplate();

        if ($template === null) {
            return ['recipient' => '', 'cc' => []];
        }

        return CommaSeparatedEmailList::resolveRecipients(
            CommaSeparatedEmailList::parse($template->to_preset),
            CommaSeparatedEmailList::parse($template->cc_preset),
        );
    }

    /**
     * @return list<string>
     */
    public function resolveTemplateEmailAddresses(): array
    {
        $template = $this->resolveAlertTemplate();

        if ($template === null) {
            return [];
        }

        return collect([
            ...CommaSeparatedEmailList::parse($template->to_preset),
            ...CommaSeparatedEmailList::parse($template->cc_preset),
        ])
            ->map(fn (string $email): string => trim($email))
            ->filter(fn (string $email): bool => $email !== '')
            ->unique(fn (string $email): string => strtolower($email))
            ->values()
            ->all();
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
     * @return Builder<EmployeeDocument>
     */
    private function pendingDocumentsQuery(int $companyId): Builder
    {
        $alertDays = $this->alertWindowDays();

        return EmployeeDocument::query()
            ->forCompany($companyId)
            ->whereExpiringWithin($alertDays)
            ->whereDoesntHave('expiryAlerts', function ($query): void {
                $query->whereColumn(
                    'employee_document_expiry_alerts.expiry_date_at_alert_time',
                    'employee_documents.expiry_date',
                );
            })
            ->with(['employee:id,company_id,name,employee_no', 'documentType:id,title']);
    }

    /**
     * Documents currently inside the expiry alert window (email dedupe ignored).
     *
     * @return Builder<EmployeeDocument>
     */
    private function inWindowDocumentsQuery(int $companyId): Builder
    {
        return EmployeeDocument::query()
            ->forCompany($companyId)
            ->whereExpiringWithin($this->alertWindowDays())
            ->with(['employee:id,company_id,name,employee_no', 'documentType:id,title']);
    }

    /**
     * @param  array{recipient: string, cc: list<string>}  $recipients
     * @param  Collection<int, EmployeeDocument>  $documents
     */
    private function sendEmailSummary(Company $company, array $recipients, Collection $documents): void
    {
        $rows = $this->buildRows($documents);

        $mail = Mail::to($recipients['recipient']);

        $ccRecipients = $this->normalizeCcRecipients($recipients['recipient'], $recipients['cc']);

        if ($ccRecipients !== []) {
            $mail->cc($ccRecipients);
        }

        $mail->send(new DocumentExpiryAlertMail(
            organizationName: (string) $company->name,
            rows: $rows,
            alertWindowDays: $this->alertWindowDays(),
            includeCompanyFooter: (bool) ($this->resolveAlertTemplate()?->include_company_footer ?? true),
        ));
    }

    /**
     * @param  Collection<int, EmployeeDocument>  $documents
     */
    private function queuePushSummaries(Company $company, Collection $documents): void
    {
        if ($documents->isEmpty()) {
            return;
        }

        $emails = $this->resolveTemplateEmailAddresses();

        if ($emails === []) {
            return;
        }

        $users = $this->resolveTemplatePushRecipients->handle($company, $emails);

        if ($users->isEmpty()) {
            return;
        }

        foreach ($users as $user) {
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
    private function recordAlerts(Collection $documents, int $companyId): void
    {
        $alertedAt = now();

        DB::transaction(function () use ($documents, $companyId, $alertedAt): void {
            foreach ($documents as $document) {
                $expiryDate = $document->expiry_date?->toDateString();

                if ($expiryDate === null || $expiryDate === '') {
                    continue;
                }

                EmployeeDocumentExpiryAlert::query()->firstOrCreate(
                    [
                        'employee_document_id' => $document->id,
                        'expiry_date_at_alert_time' => $expiryDate,
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
     * @param  list<string>  $ccRecipients
     */
    private function logSuccess(
        Company $company,
        string $recipient,
        array $ccRecipients,
        Collection $documents,
    ): void {
        activity()
            ->useLog('documents')
            ->event('expiry_alert_sent')
            ->performedOn($company)
            ->withProperties([
                'recipient' => $recipient,
                'cc' => $ccRecipients,
                'document_count' => $documents->count(),
                'company_id' => $company->id,
                'document_ids' => $documents->pluck('id')->values()->all(),
            ])
            ->tap(function (Activity $activity) use ($company): void {
                $activity->company_id = (int) $company->id;
            })
            ->log('Document expiry alert email sent');
    }

    /**
     * @param  list<string>  $ccRecipients
     * @return list<string>
     */
    private function normalizeCcRecipients(string $recipient, array $ccRecipients): array
    {
        $recipientNormalized = strtolower(trim($recipient));

        return collect($ccRecipients)
            ->map(fn (string $email) => trim($email))
            ->filter(fn (string $email) => $email !== '' && strtolower($email) !== $recipientNormalized)
            ->unique(fn (string $email) => strtolower($email))
            ->values()
            ->all();
    }
}
