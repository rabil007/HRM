<?php

namespace App\Support\Documents\RecipientRequests\Actions;

use App\Enums\DocumentRecipientRequestEventType;
use App\Enums\DocumentRecipientRequestStatus;
use App\Models\DocumentInstance;
use App\Models\DocumentInstanceVersion;
use App\Models\DocumentRecipientRequest;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestEventRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SubmitDocumentRecipientAcknowledgement
{
    public const ACKNOWLEDGEMENT_STATEMENT = 'I confirm that I have read and acknowledge this document.';

    public function __construct(
        private DocumentRecipientRequestEventRecorder $eventRecorder,
        private SupersedeStaleDocumentRecipientRequests $supersedeStale,
    ) {}

    /**
     * @param  array{name: string, acknowledgement: bool}  $data
     */
    public function handle(DocumentRecipientRequest $request, array $data, Request $httpRequest): DocumentRecipientRequest
    {
        if ($request->status === DocumentRecipientRequestStatus::Completed) {
            return $request;
        }

        if ($request->status !== DocumentRecipientRequestStatus::AwaitingAction) {
            throw ValidationException::withMessages([
                'token' => 'This acknowledgement request is no longer available.',
            ]);
        }

        if ($request->isExpired()) {
            $request->update(['status' => DocumentRecipientRequestStatus::Expired]);
            $this->eventRecorder->record($request, DocumentRecipientRequestEventType::RequestExpired);

            throw ValidationException::withMessages([
                'token' => 'This acknowledgement link has expired.',
            ]);
        }

        if ($data['acknowledgement'] !== true) {
            throw ValidationException::withMessages([
                'acknowledgement' => 'Acknowledgement confirmation is required.',
            ]);
        }

        return DB::transaction(function () use ($request, $data, $httpRequest): DocumentRecipientRequest {
            /** @var DocumentRecipientRequest $locked */
            $locked = DocumentRecipientRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === DocumentRecipientRequestStatus::Completed) {
                return $locked;
            }

            if ($locked->status !== DocumentRecipientRequestStatus::AwaitingAction) {
                throw ValidationException::withMessages([
                    'token' => 'This acknowledgement request is no longer available.',
                ]);
            }

            $instance = DocumentInstance::query()
                ->whereKey($locked->document_instance_id)
                ->where('company_id', $locked->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            $sourceVersion = DocumentInstanceVersion::query()
                ->whereKey($locked->source_document_instance_version_id)
                ->where('company_id', $locked->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $instance->current_version_id !== (int) $sourceVersion->id) {
                $this->supersedeStale->markSuperseded($locked);

                throw ValidationException::withMessages([
                    'token' => 'This document has been updated. Please request a new acknowledgement link.',
                ]);
            }

            $locked->update([
                'status' => DocumentRecipientRequestStatus::Completed,
                'completed_at' => now(),
                'signed_name' => trim($data['name']),
                'consent_at' => now(),
                'submitted_ip' => $httpRequest->ip(),
                'user_agent' => Str::limit((string) $httpRequest->userAgent(), 1000, ''),
                'acknowledgement_text_snapshot' => self::ACKNOWLEDGEMENT_STATEMENT,
            ]);

            $this->eventRecorder->record(
                $locked,
                DocumentRecipientRequestEventType::AcknowledgementSubmitted,
                ipAddress: $httpRequest->ip(),
                userAgent: Str::limit((string) $httpRequest->userAgent(), 1000, ''),
            );

            activity()
                ->performedOn($locked)
                ->tap(fn ($activity) => $activity->company_id = $locked->company_id)
                ->withProperties([
                    'action' => 'document_acknowledged',
                    'document_recipient_request_id' => $locked->id,
                    'document_instance_id' => $instance->id,
                ])
                ->log('Document acknowledged');

            return $locked->fresh();
        });
    }
}
