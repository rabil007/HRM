<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Models\WhatsAppSetting;
use App\Support\EmployeeDocuments\DocumentBulkActionService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class DocumentWhatsAppService
{
    public function __construct(
        private WhatsAppService $whatsapp,
        private DocumentBulkActionService $bulkActions,
    ) {}

    /**
     * @param  list<int>  $documentIds
     * @return array{
     *     message: string,
     *     sent_count: int,
     *     failed_count: int,
     *     results: list<array<string, mixed>>
     * }
     */
    public function sendDocuments(
        Employee $employee,
        array $documentIds,
        int $companyId,
        User $sender,
        string $whatsappNumber,
        bool $sendTemplateFirst = false,
    ): array {
        $whatsappNumber = trim($whatsappNumber);

        if ($whatsappNumber === '') {
            throw ValidationException::withMessages([
                'whatsapp_number' => 'A WhatsApp number is required.',
            ]);
        }

        if (! WhatsAppSetting::current()->isConfigured()) {
            throw ValidationException::withMessages([
                'whatsapp' => 'WhatsApp integration is not configured or enabled.',
            ]);
        }

        $documents = $this->bulkActions->documentsForEmployeeAction($documentIds, $companyId, $employee->id);
        $caption = (string) config('whatsapp.document_caption');
        $results = [];
        $sentCount = 0;
        $failedCount = 0;

        if ($sendTemplateFirst) {
            $templateResult = $this->whatsapp->sendTemplateMessage($whatsappNumber);
            $results[] = [
                'document_id' => null,
                'document_name' => 'hello_world template',
                'success' => (bool) ($templateResult['success'] ?? false),
                'status' => ($templateResult['success'] ?? false) ? 'sent' : 'failed',
                'message' => (string) ($templateResult['message'] ?? ''),
                'message_id' => $templateResult['message_id'] ?? null,
                'media_id' => null,
                'http_status' => $templateResult['http_status'] ?? null,
                'api' => $templateResult['api'] ?? null,
                'delivery_note' => $templateResult['delivery_note'] ?? null,
                'error' => ($templateResult['success'] ?? false) ? null : ($templateResult['message'] ?? 'Template send failed.'),
            ];

            if ($templateResult['success'] ?? false) {
                $sentCount++;
            } else {
                $failedCount++;
            }
        }

        foreach ($documents as $document) {
            $results[] = $this->sendSingleDocument(
                document: $document,
                companyId: $companyId,
                whatsappNumber: $whatsappNumber,
                caption: $caption,
            );

            if ($results[array_key_last($results)]['success']) {
                $sentCount++;
            } else {
                $failedCount++;
            }
        }

        $documentCount = $documents->count();
        $message = match (true) {
            $failedCount === 0 => "{$sentCount} ".($sentCount === 1 ? 'message' : 'messages').' accepted by Meta via WhatsApp.',
            $sentCount === 0 => 'Failed to send documents via WhatsApp.',
            default => "{$sentCount} accepted, {$failedCount} failed via WhatsApp.",
        };

        Log::info('WhatsApp Document Delivery Batch', [
            'employee_id' => $employee->id,
            'whatsapp_number' => $whatsappNumber,
            'document_count' => $documentCount,
            'send_template_first' => $sendTemplateFirst,
            'sent_count' => $sentCount,
            'failed_count' => $failedCount,
            'sent_by' => $sender->id,
        ]);

        return [
            'message' => $message,
            'sent_count' => $sentCount,
            'failed_count' => $failedCount,
            'results' => $results,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sendSingleDocument(
        EmployeeDocument $document,
        int $companyId,
        string $whatsappNumber,
        string $caption,
    ): array {
        $resolvedFile = $this->resolveDocumentFile($document, $companyId);

        if ($resolvedFile === null) {
            return $this->failureResult(
                document: $document,
                documentName: $this->documentFilename($document),
                errorMessage: 'Document file is not available for WhatsApp delivery.',
            );
        }

        try {
            $result = $this->whatsapp->sendDocument(
                $whatsappNumber,
                $resolvedFile['path'],
                $resolvedFile['name'],
                $caption,
                $resolvedFile['mime'],
            );

            if ($result['success']) {
                return [
                    'document_id' => $document->id,
                    'document_name' => $resolvedFile['name'],
                    'success' => true,
                    'status' => 'sent',
                    'message' => $result['message'],
                    'message_id' => $result['message_id'],
                    'media_id' => $result['media_id'] ?? null,
                    'http_status' => $result['http_status'],
                    'normalized_phone' => $result['normalized_phone'] ?? null,
                    'delivery_note' => $result['delivery_note'] ?? null,
                    'media_api' => $result['media_api'] ?? null,
                    'api' => $result['api'] ?? null,
                    'error' => null,
                ];
            }

            return $this->failureResult(
                document: $document,
                documentName: $resolvedFile['name'],
                errorMessage: (string) $result['message'],
                result: $result,
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->failureResult(
                document: $document,
                documentName: $resolvedFile['name'],
                errorMessage: $exception->getMessage(),
            );
        }
    }

    /**
     * @return array{path: string, name: string, mime: string}|null
     */
    private function resolveDocumentFile(EmployeeDocument $document, int $companyId): ?array
    {
        if ($this->isExternalUrl((string) $document->file_path)) {
            return null;
        }

        $diskPath = $this->validatedDiskPath((string) $document->file_path, $companyId);

        if ($diskPath === null || ! Storage::disk('public')->exists($diskPath)) {
            return null;
        }

        $absolutePath = Storage::disk('public')->path($diskPath);

        if (! is_readable($absolutePath)) {
            return null;
        }

        $mimeType = (string) ($document->mime_type ?: Storage::disk('public')->mimeType($diskPath) ?: 'application/pdf');

        return [
            'path' => $absolutePath,
            'name' => $this->documentFilename($document),
            'mime' => $mimeType,
        ];
    }

    private function documentFilename(EmployeeDocument $document): string
    {
        $candidate = (string) ($document->original_filename ?: $document->title ?: "document-{$document->id}");
        $basename = basename($candidate);
        $basename = preg_replace('/[^\w\.\-]+/u', '_', $basename) ?? 'document';
        $basename = trim($basename, '._');

        return $basename !== '' ? $basename : 'document';
    }

    private function isExternalUrl(string $filePath): bool
    {
        return str_starts_with($filePath, 'http://') || str_starts_with($filePath, 'https://');
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

    /**
     * @param  array<string, mixed>|null  $result
     * @return array<string, mixed>
     */
    private function failureResult(
        EmployeeDocument $document,
        string $documentName,
        string $errorMessage,
        ?array $result = null,
    ): array {
        Log::error('WhatsApp Document Delivery Failed', [
            'document_id' => $document->id,
            'document_name' => $documentName,
            'error' => $errorMessage,
        ]);

        return [
            'document_id' => $document->id,
            'document_name' => $documentName,
            'success' => false,
            'status' => 'failed',
            'message' => $errorMessage,
            'message_id' => $result['message_id'] ?? null,
            'media_id' => $result['media_id'] ?? null,
            'http_status' => $result['http_status'] ?? null,
            'normalized_phone' => $result['normalized_phone'] ?? null,
            'delivery_note' => $result['delivery_note'] ?? null,
            'media_api' => $result['media_api'] ?? null,
            'api' => $result['api'] ?? null,
            'error' => $errorMessage,
        ];
    }
}
