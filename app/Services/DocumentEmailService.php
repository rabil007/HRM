<?php

namespace App\Services;

use App\Mail\DocumentsSharedMail;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Throwable;

class DocumentEmailService
{
    /**
     * @param  Collection<int, EmployeeDocument>  $documents
     * @param  list<string>  $ccRecipients
     */
    public function send(
        Collection $documents,
        Employee $employee,
        Company $company,
        User $sender,
        string $recipient,
        array $ccRecipients,
        string $subject,
        ?string $message,
    ): void {
        $ccRecipients = $this->normalizeCcRecipients($recipient, $ccRecipients);

        $attachments = $this->resolveAttachments($documents, (int) $company->id);
        $this->assertAttachmentSizeWithinLimit($attachments);

        $summaries = array_map(
            fn (array $attachment) => [
                'name' => $attachment['name'],
                'size_bytes' => $attachment['size_bytes'],
                'mime_type' => $attachment['mime'],
            ],
            $attachments,
        );

        try {
            $mail = Mail::to($recipient);

            if ($ccRecipients !== []) {
                $mail->cc($ccRecipients);
            }

            $mail->send(new DocumentsSharedMail(
                organizationName: (string) $company->name,
                senderName: (string) $sender->name,
                subjectLine: $subject,
                bodyMessage: $message,
                attachmentSummaries: $summaries,
                fileAttachments: array_map(
                    fn (array $attachment) => [
                        'path' => $attachment['path'],
                        'name' => $attachment['name'],
                        'mime' => $attachment['mime'],
                    ],
                    $attachments,
                ),
            ));
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'recipient' => 'Unable to send email. Please try again later.',
            ]);
        }

        $this->logEmailActivity(
            employee: $employee,
            sender: $sender,
            companyId: (int) $company->id,
            recipient: $recipient,
            ccRecipients: $ccRecipients,
            documentCount: $documents->count(),
        );
    }

    /**
     * @param  Collection<int, EmployeeDocument>  $documents
     * @return list<array{path: string, name: string, mime: string, size_bytes: int}>
     */
    private function resolveAttachments(Collection $documents, int $companyId): array
    {
        if ($documents->isEmpty()) {
            throw ValidationException::withMessages([
                'document_ids' => 'Select at least one document to email.',
            ]);
        }

        $attachments = [];
        $usedNames = [];

        foreach ($documents as $document) {
            if ($this->isExternalUrl((string) $document->file_path)) {
                throw ValidationException::withMessages([
                    'document_ids' => 'One or more selected files cannot be attached.',
                ]);
            }

            $diskPath = $this->validatedDiskPath((string) $document->file_path, $companyId);

            if ($diskPath === null || ! Storage::disk('public')->exists($diskPath)) {
                throw ValidationException::withMessages([
                    'document_ids' => 'One or more selected files could not be found.',
                ]);
            }

            $absolutePath = Storage::disk('public')->path($diskPath);

            if (! is_readable($absolutePath)) {
                throw ValidationException::withMessages([
                    'document_ids' => 'One or more selected files could not be read.',
                ]);
            }

            $mimeType = (string) ($document->mime_type ?: Storage::disk('public')->mimeType($diskPath) ?: 'application/octet-stream');

            if (! $this->isAllowedMimeType($mimeType)) {
                throw ValidationException::withMessages([
                    'document_ids' => 'One or more selected files uses an unsupported file type for email.',
                ]);
            }

            $fileSize = (int) ($document->size_bytes ?: (filesize($absolutePath) ?: 0));

            if ($fileSize <= 0) {
                throw ValidationException::withMessages([
                    'document_ids' => 'One or more selected files appear to be empty or corrupted.',
                ]);
            }

            $attachments[] = [
                'path' => $absolutePath,
                'name' => $this->uniqueAttachmentName($document, $usedNames),
                'mime' => $mimeType,
                'size_bytes' => $fileSize,
            ];
        }

        return $attachments;
    }

    /**
     * @param  list<array{path: string, name: string, mime: string, size_bytes: int}>  $attachments
     */
    private function assertAttachmentSizeWithinLimit(array $attachments): void
    {
        $totalBytes = array_sum(array_column($attachments, 'size_bytes'));
        $maxBytes = (int) config('services.documents.email_max_attachment_bytes', 20 * 1024 * 1024);

        if ($totalBytes > $maxBytes) {
            $limitMb = round($maxBytes / 1024 / 1024, 1);

            throw ValidationException::withMessages([
                'document_ids' => "Total attachment size exceeds the {$limitMb} MB limit. Remove some files or send fewer documents.",
            ]);
        }
    }

    /**
     * @param  array<string, int>  $usedNames
     */
    private function uniqueAttachmentName(EmployeeDocument $document, array &$usedNames): string
    {
        $entryName = $this->attachmentFilename($document);

        if (! isset($usedNames[$entryName])) {
            $usedNames[$entryName] = 1;

            return $entryName;
        }

        $usedNames[$entryName]++;
        $pathInfo = pathinfo($entryName);
        $basename = $pathInfo['filename'] ?? 'document';
        $extension = isset($pathInfo['extension']) ? '.'.$pathInfo['extension'] : '';

        return "{$basename}_{$usedNames[$entryName]}{$extension}";
    }

    private function attachmentFilename(EmployeeDocument $document): string
    {
        $candidate = (string) ($document->original_filename ?: $document->title ?: "document-{$document->id}");
        $basename = basename($candidate);
        $basename = preg_replace('/[^\w\.\-]+/u', '_', $basename) ?? 'document';
        $basename = trim($basename, '._');

        return $basename !== '' ? $basename : 'document';
    }

    private function isAllowedMimeType(string $mimeType): bool
    {
        $allowed = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.oasis.opendocument.text',
            'application/vnd.oasis.opendocument.spreadsheet',
            'application/vnd.oasis.opendocument.presentation',
            'text/plain',
            'text/csv',
            'application/csv',
        ];

        if (in_array($mimeType, $allowed, true)) {
            return true;
        }

        return str_starts_with($mimeType, 'image/');
    }

    /**
     * @param  list<string>  $ccRecipients
     */
    private function logEmailActivity(
        Employee $employee,
        User $sender,
        int $companyId,
        string $recipient,
        array $ccRecipients,
        int $documentCount,
    ): void {
        activity()
            ->useLog('documents')
            ->event('emailed')
            ->causedBy($sender)
            ->performedOn($employee)
            ->withProperties([
                'recipient' => $recipient,
                'cc' => $ccRecipients,
                'document_count' => $documentCount,
                'employee_id' => $employee->id,
            ])
            ->tap(function (Activity $activity) use ($companyId): void {
                $activity->company_id = $companyId;
            })
            ->log('Employee documents sent via email');
    }

    private function isExternalUrl(string $filePath): bool
    {
        return str_starts_with($filePath, 'http://') || str_starts_with($filePath, 'https://');
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

    private function validatedDiskPath(string $filePath, int $companyId): ?string
    {
        $filePath = ltrim($filePath, '/');

        if ($filePath === '' || str_contains($filePath, '..')) {
            return null;
        }

        $expectedPrefix = "employee-documents/{$companyId}/";

        if (! str_starts_with($filePath, $expectedPrefix)) {
            return null;
        }

        return $filePath;
    }
}
