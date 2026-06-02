<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Models\WhatsAppDocumentDelivery;
use App\Models\WhatsAppSetting;
use App\Support\EmployeeDocuments\DocumentBulkActionService;
use App\Support\EmployeeDocuments\DocumentDownloadService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class DocumentWhatsAppService
{
    public function __construct(
        private WhatsAppService $whatsapp,
        private DocumentBulkActionService $bulkActions,
        private DocumentDownloadService $downloads,
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

        foreach ($documents as $document) {
            $results[] = $this->sendSingleDocument(
                employee: $employee,
                document: $document,
                companyId: $companyId,
                sender: $sender,
                whatsappNumber: $whatsappNumber,
                caption: $caption,
            );

            if ($results[array_key_last($results)]['success']) {
                $sentCount++;
            } else {
                $failedCount++;
            }
        }

        $message = match (true) {
            $failedCount === 0 => "{$sentCount} ".($sentCount === 1 ? 'document' : 'documents').' sent via WhatsApp.',
            $sentCount === 0 => 'Failed to send documents via WhatsApp.',
            default => "{$sentCount} sent, {$failedCount} failed via WhatsApp.",
        };

        Log::info('WhatsApp Document Delivery Batch', [
            'employee_id' => $employee->id,
            'whatsapp_number' => $whatsappNumber,
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
        Employee $employee,
        EmployeeDocument $document,
        int $companyId,
        User $sender,
        string $whatsappNumber,
        string $caption,
    ): array {
        $documentName = $this->downloads->downloadFilenameForDocument($document);
        $resolvedPath = $this->downloads->resolveAbsolutePath($document, $companyId);

        if ($resolvedPath === null) {
            return $this->recordFailure(
                employee: $employee,
                document: $document,
                companyId: $companyId,
                sender: $sender,
                whatsappNumber: $whatsappNumber,
                documentName: $documentName,
                errorMessage: 'Document file is not available for WhatsApp delivery.',
                requestPayload: null,
                responsePayload: null,
                mediaId: null,
                messageId: null,
            );
        }

        $absolutePath = $resolvedPath['path'];
        $temporary = $resolvedPath['temporary'];
        $mediaId = null;

        try {
            $mediaId = $this->whatsapp->uploadDocument(
                $absolutePath,
                (string) ($document->mime_type ?: 'application/pdf'),
                $documentName,
            );

            $result = $this->whatsapp->sendDocumentMessage(
                $whatsappNumber,
                $mediaId,
                $documentName,
                $caption,
                [
                    'employee_id' => $employee->id,
                    'document_id' => $document->id,
                    'whatsapp_number' => $whatsappNumber,
                ],
            );

            if ($result['success']) {
                $delivery = $this->recordSuccess(
                    employee: $employee,
                    document: $document,
                    companyId: $companyId,
                    sender: $sender,
                    whatsappNumber: $whatsappNumber,
                    mediaId: $mediaId,
                    messageId: $result['message_id'],
                    requestPayload: $result['request_payload'],
                    responsePayload: $result['response_payload'],
                );

                return [
                    'document_id' => $document->id,
                    'document_name' => $documentName,
                    'success' => true,
                    'status' => 'sent',
                    'message' => $result['message'],
                    'message_id' => $result['message_id'],
                    'media_id' => $mediaId,
                    'http_status' => $result['http_status'],
                    'request_payload' => $result['request_payload'],
                    'response_payload' => $result['response_payload'],
                    'error' => null,
                    'delivery_id' => $delivery->id,
                ];
            }

            return $this->recordFailure(
                employee: $employee,
                document: $document,
                companyId: $companyId,
                sender: $sender,
                whatsappNumber: $whatsappNumber,
                documentName: $documentName,
                errorMessage: (string) ($result['error'] ?? $result['message']),
                requestPayload: $result['request_payload'],
                responsePayload: $result['response_payload'],
                mediaId: $mediaId,
                messageId: null,
                httpStatus: $result['http_status'] ?? null,
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->recordFailure(
                employee: $employee,
                document: $document,
                companyId: $companyId,
                sender: $sender,
                whatsappNumber: $whatsappNumber,
                documentName: $documentName,
                errorMessage: $exception->getMessage(),
                requestPayload: null,
                responsePayload: null,
                mediaId: $mediaId,
                messageId: null,
            );
        } finally {
            if ($temporary) {
                @unlink($absolutePath);
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $requestPayload
     * @param  array<string, mixed>|null  $responsePayload
     */
    private function recordSuccess(
        Employee $employee,
        EmployeeDocument $document,
        int $companyId,
        User $sender,
        string $whatsappNumber,
        string $mediaId,
        ?string $messageId,
        ?array $requestPayload,
        ?array $responsePayload,
    ): WhatsAppDocumentDelivery {
        return WhatsAppDocumentDelivery::query()->create([
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            'employee_document_id' => $document->id,
            'whatsapp_number' => $whatsappNumber,
            'media_id' => $mediaId,
            'message_id' => $messageId,
            'status' => 'sent',
            'error_message' => null,
            'request_payload' => $requestPayload,
            'response_payload' => $responsePayload,
            'sent_by' => $sender->id,
            'sent_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $requestPayload
     * @param  array<string, mixed>|null  $responsePayload
     * @return array<string, mixed>
     */
    private function recordFailure(
        Employee $employee,
        EmployeeDocument $document,
        int $companyId,
        User $sender,
        string $whatsappNumber,
        string $documentName,
        string $errorMessage,
        ?array $requestPayload,
        ?array $responsePayload,
        ?string $mediaId,
        ?string $messageId,
        ?int $httpStatus = null,
    ): array {
        $delivery = WhatsAppDocumentDelivery::query()->create([
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            'employee_document_id' => $document->id,
            'whatsapp_number' => $whatsappNumber,
            'media_id' => $mediaId,
            'message_id' => $messageId,
            'status' => 'failed',
            'error_message' => $errorMessage,
            'request_payload' => $requestPayload,
            'response_payload' => $responsePayload,
            'sent_by' => $sender->id,
            'sent_at' => now(),
        ]);

        Log::error('WhatsApp Document Delivery Failed', [
            'employee_id' => $employee->id,
            'document_id' => $document->id,
            'whatsapp_number' => $whatsappNumber,
            'media_id' => $mediaId,
            'message_id' => $messageId,
            'error' => $errorMessage,
            'delivery_id' => $delivery->id,
        ]);

        return [
            'document_id' => $document->id,
            'document_name' => $documentName,
            'success' => false,
            'status' => 'failed',
            'message' => $errorMessage,
            'message_id' => $messageId,
            'media_id' => $mediaId,
            'http_status' => $httpStatus,
            'request_payload' => $requestPayload,
            'response_payload' => $responsePayload,
            'error' => $errorMessage,
            'delivery_id' => $delivery->id,
        ];
    }
}
