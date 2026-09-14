<?php

namespace App\Services;

use App\Mail\CompanyDocumentExpiryAlertMail;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\CompanyDocumentExpiryAlert;
use App\Models\CompanyDocumentExpiryNotificationSetting;
use App\Models\EmailTemplate;
use App\Support\EmployeeDocuments\DocumentExpiry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;
use Throwable;

class CompanyDocumentExpiryAlertService
{
    public function hasPendingDocuments(int $companyId): bool
    {
        $setting = $this->resolveNotificationSetting($companyId);

        if ($setting === null || ! $setting->enabled) {
            return false;
        }

        if ($setting->toRecipients()->doesntExist()) {
            return false;
        }

        return $this->pendingDocumentsQuery($companyId)->exists();
    }

    public function sendForCompany(int $companyId): void
    {
        $company = Company::query()->findOrFail($companyId);

        if ($company->status !== 'active') {
            return;
        }

        $setting = $this->resolveNotificationSetting($companyId);

        if ($setting === null || ! $setting->enabled) {
            return;
        }

        $toRecipients = $setting->toRecipients()
            ->with('user:id,name,email')
            ->get()
            ->filter(fn ($r) => $r->user !== null && filled($r->user->email))
            ->values();

        if ($toRecipients->isEmpty()) {
            return;
        }

        $ccRecipients = $setting->ccRecipients()
            ->with('user:id,name,email')
            ->get()
            ->filter(fn ($r) => $r->user !== null && filled($r->user->email))
            ->values();

        $documents = $this->pendingDocumentsQuery($companyId)->get();

        if ($documents->isEmpty()) {
            return;
        }

        try {
            $this->sendEmailSummary($company, $toRecipients, $ccRecipients, $documents);
            $this->recordAlerts($documents, $companyId);
            $this->logSuccess($company, $toRecipients, $ccRecipients, $documents);
        } catch (Throwable $exception) {
            $this->logFailure($company, $exception);
            throw $exception;
        }
    }

    public function logFailure(Company $company, Throwable $exception): void
    {
        report($exception);

        activity()
            ->useLog('documents')
            ->event('company_document_expiry_alert_failed')
            ->performedOn($company)
            ->withProperties([
                'company_id' => $company->id,
                'message' => $exception->getMessage(),
            ])
            ->tap(function (Activity $activity) use ($company): void {
                $activity->company_id = (int) $company->id;
            })
            ->log('Company document expiry alert email failed');
    }

    public function alertWindowDays(): int
    {
        return (int) config('documents.expiry_alert_days');
    }

    private function resolveNotificationSetting(int $companyId): ?CompanyDocumentExpiryNotificationSetting
    {
        return CompanyDocumentExpiryNotificationSetting::query()
            ->where('company_id', $companyId)
            ->first();
    }

    /**
     * @return Builder<CompanyDocument>
     */
    private function pendingDocumentsQuery(int $companyId): Builder
    {
        $alertDays = $this->alertWindowDays();

        return CompanyDocument::query()
            ->forCompany($companyId)
            ->whereExpiringWithin($alertDays)
            ->whereDoesntHave('expiryAlerts', function ($query): void {
                $query->whereColumn(
                    'company_document_expiry_alerts.expiry_date_at_alert_time',
                    'company_documents.expiry_date',
                );
            })
            ->with(['documentType:id,title']);
    }

    /**
     * @param  Collection<int, mixed>  $toRecipients
     * @param  Collection<int, mixed>  $ccRecipients
     * @param  Collection<int, CompanyDocument>  $documents
     */
    private function sendEmailSummary(
        Company $company,
        Collection $toRecipients,
        Collection $ccRecipients,
        Collection $documents,
    ): void {
        $rows = $this->buildRows($company, $documents);
        $alertWindowDays = $this->alertWindowDays();
        $template = $this->resolveAlertTemplate();
        $includeCompanyFooter = (bool) ($template?->include_company_footer ?? true);

        $toAddresses = $toRecipients->map(fn ($r) => $r->user->email)->unique(fn ($e) => strtolower($e))->values()->all();
        $ccAddresses = $ccRecipients
            ->map(fn ($r) => $r->user->email)
            ->reject(fn ($email) => in_array(strtolower($email), array_map('strtolower', $toAddresses), true))
            ->unique(fn ($e) => strtolower($e))
            ->values()
            ->all();

        $mailer = Mail::to($toAddresses[0]);

        $remainingTo = array_slice($toAddresses, 1);
        if ($remainingTo !== []) {
            $mailer->cc($remainingTo);
        }

        if ($ccAddresses !== []) {
            $mailer->cc($ccAddresses);
        }

        $mailer->send(new CompanyDocumentExpiryAlertMail(
            organizationName: (string) $company->name,
            rows: $rows,
            alertWindowDays: $alertWindowDays,
            includeCompanyFooter: $includeCompanyFooter,
        ));
    }

    /**
     * @param  Collection<int, CompanyDocument>  $documents
     * @return list<array{document_name: string, document_number: string|null, expiry_date: string, days_remaining: int, view_url: string}>
     */
    private function buildRows(Company $company, Collection $documents): array
    {
        return $documents
            ->sortBy(fn (CompanyDocument $document) => $document->expiry_date?->toDateString() ?? '')
            ->values()
            ->map(function (CompanyDocument $document) use ($company): array {
                $expiryDate = $document->expiry_date?->toDateString() ?? '';

                return [
                    'document_name' => $document->title
                        ?? $document->documentType?->title
                        ?? $document->original_filename,
                    'document_number' => $document->document_number,
                    'expiry_date' => $expiryDate,
                    'days_remaining' => DocumentExpiry::remainingDays($document->expiry_date) ?? 0,
                    'view_url' => route('organization.companies.documents.index', $company),
                ];
            })
            ->all();
    }

    /**
     * @param  Collection<int, CompanyDocument>  $documents
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

                CompanyDocumentExpiryAlert::query()->firstOrCreate(
                    [
                        'company_document_id' => $document->id,
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
     * @param  Collection<int, mixed>  $toRecipients
     * @param  Collection<int, mixed>  $ccRecipients
     * @param  Collection<int, CompanyDocument>  $documents
     */
    private function logSuccess(
        Company $company,
        Collection $toRecipients,
        Collection $ccRecipients,
        Collection $documents,
    ): void {
        activity()
            ->useLog('documents')
            ->event('company_document_expiry_alert_sent')
            ->performedOn($company)
            ->withProperties([
                'to_recipients' => $toRecipients->map(fn ($r) => $r->user->email)->values()->all(),
                'cc_recipients' => $ccRecipients->map(fn ($r) => $r->user->email)->values()->all(),
                'document_count' => $documents->count(),
                'company_id' => $company->id,
                'document_ids' => $documents->pluck('id')->values()->all(),
            ])
            ->tap(function (Activity $activity) use ($company): void {
                $activity->company_id = (int) $company->id;
            })
            ->log('Company document expiry alert email sent');
    }

    private function resolveAlertTemplate(): ?EmailTemplate
    {
        $slug = (string) config('documents.company_expiry_alert_template_slug', 'company_document_expiry_alert');

        return EmailTemplate::query()
            ->where('slug', $slug)
            ->where('enabled', true)
            ->first();
    }
}
