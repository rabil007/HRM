<?php

namespace App\Support\Documents\RecipientRequests\Actions;

use App\Enums\DocumentRecipientRequestEventType;
use App\Enums\DocumentRecipientRequestStatus;
use App\Models\DocumentInstance;
use App\Models\DocumentInstanceVersion;
use App\Models\DocumentRecipientRequest;
use App\Support\Documents\RecipientRequests\DocumentRecipientAcceptedFlag;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestEventRecorder;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestSourceGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SubmitDocumentRecipientAcknowledgement
{
    public const ACKNOWLEDGEMENT_STATEMENT = 'I confirm that I have read and acknowledge this document.';

    private const STALE_VERSION = '__stale_version__';

    public function __construct(
        private DocumentRecipientRequestEventRecorder $eventRecorder,
        private SupersedeStaleDocumentRecipientRequests $supersedeStale,
        private DocumentRecipientRequestSourceGuard $sourceGuard,
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
            app(ExpireDocumentRecipientRequest::class)->handle($request);

            throw ValidationException::withMessages([
                'token' => 'This acknowledgement link has expired.',
            ]);
        }

        if (! DocumentRecipientAcceptedFlag::isAccepted($data['acknowledgement'] ?? false)) {
            throw ValidationException::withMessages([
                'acknowledgement' => 'Acknowledgement confirmation is required.',
            ]);
        }

        $result = DB::transaction(function () use ($request, $data, $httpRequest): DocumentRecipientRequest|string|array {
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

            if ($locked->expires_at !== null && $locked->expires_at->lessThanOrEqualTo(now())) {
                app(ExpireDocumentRecipientRequest::class)->transitionLocked($locked);

                return ['__expired' => true];
            }

            $instance = DocumentInstance::query()
                ->whereKey($locked->document_instance_id)
                ->where('company_id', $locked->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            $sourceVersion = DocumentInstanceVersion::query()
                ->whereKey($locked->source_document_instance_version_id)
                ->where('company_id', $locked->company_id)
                ->where('document_instance_id', $locked->document_instance_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $instance->current_version_id !== (int) $sourceVersion->id) {
                $this->supersedeStale->markSuperseded($locked, [
                    'document_instance_id' => $instance->id,
                    'current_version_id' => $instance->current_version_id,
                ]);

                return self::STALE_VERSION;
            }

            $this->sourceGuard->assertExactSource($locked, $sourceVersion);

            $locked->update([
                'status' => DocumentRecipientRequestStatus::Completed,
                'completed_at' => now(),
                'signed_name' => trim($data['name']),
                'consent_at' => now(),
                'submitted_ip' => $httpRequest->ip(),
                'user_agent' => Str::limit((string) $httpRequest->userAgent(), 1000, ''),
                'acknowledgement_text_snapshot' => self::ACKNOWLEDGEMENT_STATEMENT,
                'next_reminder_at' => null,
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

        if ($result === self::STALE_VERSION) {
            throw ValidationException::withMessages([
                'token' => 'This document has been updated. Please request a new acknowledgement link.',
            ]);
        }

        if (is_array($result) && ($result['__expired'] ?? false) === true) {
            throw ValidationException::withMessages([
                'token' => 'This acknowledgement link has expired.',
            ]);
        }

        /** @var DocumentRecipientRequest $result */
        return $result;
    }
}
