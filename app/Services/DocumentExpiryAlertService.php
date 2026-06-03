<?php

namespace App\Services;

use App\Mail\DocumentExpiryAlertMail;
use App\Models\Company;
use App\Models\EmailTemplate;
use App\Models\EmployeeDocument;
use App\Models\EmployeeDocumentExpiryAlert;
use App\Support\Email\CommaSeparatedEmailList;
use App\Support\EmployeeDocuments\DocumentExpiry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class DocumentExpiryAlertService
{
    public function sendForCompany(Company $company): int
    {
        $template = $this->resolveTemplate();

        if ($template === null) {
            return 0;
        }

        $recipients = CommaSeparatedEmailList::resolveRecipients(
            CommaSeparatedEmailList::parse($template->to_preset),
            CommaSeparatedEmailList::parse($template->cc_preset),
        );

        if ($recipients['recipient'] === '') {
            return 0;
        }

        $alertDays = (int) config('documents.expiry_alert_days', 30);

        $documents = $this->pendingDocuments((int) $company->id, $alertDays);

        if ($documents->isEmpty()) {
            return 0;
        }

        $employeeGroups = $this->buildEmployeeGroups($documents);

        try {
            $mail = Mail::to($recipients['recipient']);

            if ($recipients['cc'] !== []) {
                $mail->cc($recipients['cc']);
            }

            $mail->send(new DocumentExpiryAlertMail(
                organizationName: (string) $company->name,
                subjectLine: (string) $template->subject,
                introMessage: trim((string) $template->body_html),
                employeeGroups: $employeeGroups,
                alertWindowDays: $alertDays,
            ));
        } catch (Throwable $exception) {
            report($exception);

            return 0;
        }

        $this->recordAlerts($documents, (int) $company->id);

        return $documents->count();
    }

    private function resolveTemplate(): ?EmailTemplate
    {
        $slug = (string) config('documents.expiry_alert_template_slug', 'document_expiry_alert');

        return EmailTemplate::query()
            ->where('slug', $slug)
            ->where('enabled', true)
            ->first();
    }

    /**
     * @return Collection<int, EmployeeDocument>
     */
    private function pendingDocuments(int $companyId, int $alertDays): Collection
    {
        $alreadyAlerted = EmployeeDocumentExpiryAlert::query()
            ->where('company_id', $companyId)
            ->pluck('employee_document_id');

        return EmployeeDocument::query()
            ->forCompany($companyId)
            ->whereExpiringWithin($alertDays)
            ->when($alreadyAlerted->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $alreadyAlerted))
            ->with(['employee:id,company_id,name', 'documentType:id,title'])
            ->get();
    }

    /**
     * @param  Collection<int, EmployeeDocument>  $documents
     * @return list<array{employee_name: string, documents: list<array{document_name: string, document_type: string, expiry_date: string, remaining_days: int}>}>
     */
    private function buildEmployeeGroups(Collection $documents): array
    {
        return $documents
            ->groupBy('employee_id')
            ->sortBy(fn (Collection $group) => (string) $group->first()?->employee?->name)
            ->map(function (Collection $group): array {
                $employeeName = (string) ($group->first()?->employee?->name ?? 'Unknown employee');

                $documentRows = $group
                    ->sortBy('expiry_date')
                    ->map(function (EmployeeDocument $document): array {
                        $expiryDate = $document->expiry_date?->toDateString() ?? '';

                        return [
                            'document_name' => $document->original_filename
                                ?? $document->title
                                ?? $document->document_type_label,
                            'document_type' => $document->document_type_label,
                            'expiry_date' => $expiryDate,
                            'remaining_days' => DocumentExpiry::remainingDays($document->expiry_date) ?? 0,
                        ];
                    })
                    ->values()
                    ->all();

                return [
                    'employee_name' => $employeeName,
                    'documents' => $documentRows,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, EmployeeDocument>  $documents
     */
    private function recordAlerts(Collection $documents, int $companyId): void
    {
        $now = now();

        DB::transaction(function () use ($documents, $companyId, $now): void {
            foreach ($documents as $document) {
                EmployeeDocumentExpiryAlert::query()->create([
                    'company_id' => $companyId,
                    'employee_document_id' => $document->id,
                    'sent_at' => $now,
                ]);
            }
        });
    }
}
