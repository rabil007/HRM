<?php

namespace App\Support\Documents\RecipientRequests\Actions;

use App\Enums\DocumentRecipientRequestEventType;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentRecipientRole;
use App\Models\DocumentInstance;
use App\Models\DocumentInstanceVersion;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentSigningFlow;
use App\Models\User;
use App\Support\Documents\DocumentInstanceStorage;
use App\Support\Documents\RecipientRequests\DocumentRecipientAcceptedFlag;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestAccess;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestEventRecorder;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestSourceGuard;
use App\Support\Documents\RecipientRequests\DocumentRecipientSignatureStorage;
use App\Support\Documents\RecipientRequests\DocumentRecipientSigningTransactionProbe;
use App\Support\Documents\RecipientRequests\ResolveDocumentSignaturePlacement;
use App\Support\Documents\RecipientRequests\SignedDocumentLibraryReplacement;
use App\Support\Documents\RecipientRequests\StampSignedDocumentInstancePdf;
use App\Support\Documents\RecipientRequests\SyncSignedDocumentInstanceToLibrary;
use App\Support\Documents\Signing\Actions\AdvanceDocumentSigningFlow;
use App\Support\Documents\Signing\DocumentSignatureSlot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SubmitDocumentRecipientSignature
{
    private const STALE_VERSION = '__stale_version__';

    public function __construct(
        private ResolveDocumentSignaturePlacement $resolvePlacement,
        private StampSignedDocumentInstancePdf $stamper,
        private SyncSignedDocumentInstanceToLibrary $syncLibrary,
        private DocumentRecipientRequestEventRecorder $eventRecorder,
        private SupersedeStaleDocumentRecipientRequests $supersedeStale,
        private DocumentRecipientRequestSourceGuard $sourceGuard,
    ) {}

    /**
     * @param  array{signed_name: string, signature_data: string, consent: bool}  $data
     */
    public function handle(
        DocumentRecipientRequest $request,
        array $data,
        Request $httpRequest,
        ?User $actor = null,
    ): DocumentRecipientRequest {
        if ($request->isInternalSigner()) {
            if ($actor === null) {
                throw ValidationException::withMessages([
                    'request' => 'This signing request requires authentication.',
                ]);
            }

            DocumentRecipientRequestAccess::assertAssignedInternalSigner(
                $request,
                $actor,
                (int) $request->company_id,
            );
        }
        if ($request->status === DocumentRecipientRequestStatus::Completed) {
            return $request;
        }

        if ($request->status !== DocumentRecipientRequestStatus::AwaitingAction) {
            throw ValidationException::withMessages([
                'token' => 'This signing request is no longer available.',
            ]);
        }

        if ($request->isExpired()) {
            app(ExpireDocumentRecipientRequest::class)->handle($request);

            throw ValidationException::withMessages([
                'token' => 'This signing link has expired.',
            ]);
        }

        if (! DocumentRecipientAcceptedFlag::isAccepted($data['consent'] ?? false)) {
            throw ValidationException::withMessages([
                'consent' => 'Electronic signing consent is required.',
            ]);
        }

        $signaturePath = null;
        $tempSignedPath = null;
        $canonicalPath = null;
        $libraryReplacement = null;

        try {
            $result = DB::transaction(function () use ($request, $data, $httpRequest, $actor, &$signaturePath, &$tempSignedPath, &$canonicalPath, &$libraryReplacement): DocumentRecipientRequest|string|array {
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
                        'token' => 'This signing request is no longer available.',
                    ]);
                }

                if ($locked->expires_at !== null && $locked->expires_at->lessThanOrEqualTo(now())) {
                    $expiry = app(ExpireDocumentRecipientRequest::class)->transitionLocked($locked);

                    return [
                        '__expired' => true,
                        'flow_id' => $expiry['flow_id'] ?? null,
                        'company_id' => $expiry['company_id'] ?? (int) $locked->company_id,
                    ];
                }

                if ($locked->isInternalSigner()) {
                    if ($actor === null) {
                        throw ValidationException::withMessages([
                            'request' => 'This signing request requires authentication.',
                        ]);
                    }

                    DocumentRecipientRequestAccess::assertAssignedInternalSigner(
                        $locked,
                        $actor,
                        (int) $locked->company_id,
                    );
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

                $placementRole = $locked->recipient_role ?? DocumentRecipientRole::Subject;
                $slotKey = filled($locked->signature_slot_key)
                    ? (string) $locked->signature_slot_key
                    : DocumentSignatureSlot::defaultForRole($placementRole);
                $placement = $this->resolvePlacement->forInstanceVersionSlot(
                    $instance,
                    $sourceVersion,
                    $placementRole,
                    $slotKey,
                );

                $signaturePath = DocumentRecipientSignatureStorage::storeFromDataUri(
                    $data['signature_data'],
                    (int) $locked->company_id,
                    (int) $locked->id,
                );

                [, $extension] = DocumentRecipientSignatureStorage::decodeDataUri($data['signature_data']);
                $imageType = $extension === 'jpg' ? 'JPEG' : 'PNG';
                $signatureAbsolute = Storage::disk(DocumentRecipientSignatureStorage::DISK)->path($signaturePath);

                $signedPdf = $this->stamper->handle($sourceVersion, $signatureAbsolute, $imageType, $placement);

                $tempSignedPath = tempnam(sys_get_temp_dir(), 'signed_doc_');

                if ($tempSignedPath === false) {
                    throw ValidationException::withMessages([
                        'signature_data' => 'Unable to produce signed PDF.',
                    ]);
                }

                file_put_contents($tempSignedPath, $signedPdf);

                $artifact = DocumentInstanceStorage::storePdf($tempSignedPath, (int) $locked->company_id);
                $canonicalPath = $artifact['path'];

                $nextVersionNumber = (int) DocumentInstanceVersion::query()
                    ->where('document_instance_id', $instance->id)
                    ->max('version') + 1;

                $resultVersion = DocumentInstanceVersion::query()->create([
                    'company_id' => $locked->company_id,
                    'document_instance_id' => $instance->id,
                    'version' => $nextVersionNumber,
                    'stage' => 'signed',
                    'file_path' => $artifact['path'],
                    'original_filename' => Str::slug($instance->title_snapshot).'-signed-v'.$nextVersionNumber.'.pdf',
                    'mime_type' => 'application/pdf',
                    'size_bytes' => $artifact['size_bytes'],
                    'checksum' => $artifact['checksum'],
                    'created_by' => $actor?->id,
                ]);

                $instance->update([
                    'current_version_id' => $resultVersion->id,
                    'status' => 'signed',
                ]);

                $locked->update([
                    'status' => DocumentRecipientRequestStatus::Completed,
                    'completed_at' => now(),
                    'signed_name' => trim($data['signed_name']),
                    'signature_image_path' => $signaturePath,
                    'consent_at' => now(),
                    'submitted_ip' => $httpRequest->ip(),
                    'user_agent' => Str::limit((string) $httpRequest->userAgent(), 1000, ''),
                    'result_document_instance_version_id' => $resultVersion->id,
                    'result_checksum_sha256' => $artifact['checksum'],
                    'next_reminder_at' => null,
                ]);

                $this->supersedeStale->forInstanceVersionChange(
                    $instance->fresh(),
                    (int) $locked->company_id,
                    excludeRequestId: (int) $locked->id,
                );

                $libraryReplacement = $this->syncLibrary->prepareReplacement(
                    $instance->fresh(),
                    $tempSignedPath,
                    (int) $locked->company_id,
                );

                if ($libraryReplacement instanceof SignedDocumentLibraryReplacement) {
                    DB::afterCommit(fn () => $this->syncLibrary->finalizeReplacement($libraryReplacement));
                }

                DocumentRecipientSigningTransactionProbe::afterLibrarySync();

                $this->eventRecorder->record(
                    $locked,
                    DocumentRecipientRequestEventType::SignatureSubmitted,
                    $actor,
                    ipAddress: $httpRequest->ip(),
                    userAgent: Str::limit((string) $httpRequest->userAgent(), 1000, ''),
                );

                $this->eventRecorder->record(
                    $locked,
                    DocumentRecipientRequestEventType::SignedVersionCreated,
                    $actor,
                    metadata: [
                        'result_document_instance_version_id' => $resultVersion->id,
                    ],
                );

                activity()
                    ->causedBy($actor)
                    ->performedOn($locked)
                    ->tap(fn ($activity) => $activity->company_id = $locked->company_id)
                    ->withProperties([
                        'action' => 'signed_document_version_created',
                        'document_recipient_request_id' => $locked->id,
                        'document_instance_id' => $instance->id,
                        'result_document_instance_version_id' => $resultVersion->id,
                    ])
                    ->log('Signed document version created');

                return $locked->fresh(['resultVersion', 'sourceVersion']);
            });

            if ($result === self::STALE_VERSION) {
                throw ValidationException::withMessages([
                    'token' => 'This document has been updated. Please request a new signing link.',
                ]);
            }

            if (is_array($result) && ($result['__expired'] ?? false) === true) {
                app(ExpireDocumentRecipientRequest::class)->blockFlowAfterCommit(
                    $result['flow_id'] ?? null,
                    (int) $result['company_id'],
                );

                throw ValidationException::withMessages([
                    'token' => 'This signing link has expired.',
                ]);
            }

            /** @var DocumentRecipientRequest $result */
            if ($result->document_signing_flow_id !== null) {
                $flowId = (int) $result->document_signing_flow_id;
                $requestId = (int) $result->id;

                DB::afterCommit(function () use ($flowId, $requestId): void {
                    try {
                        $flow = DocumentSigningFlow::query()->find($flowId);
                        $completed = DocumentRecipientRequest::query()->find($requestId);

                        if ($flow === null || $completed === null) {
                            return;
                        }

                        app(AdvanceDocumentSigningFlow::class)->handle($flow, $completed);
                    } catch (\Throwable $exception) {
                        report($exception);
                    }
                });
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($libraryReplacement instanceof SignedDocumentLibraryReplacement) {
                $this->syncLibrary->rollbackReplacement($libraryReplacement);
            }

            if ($canonicalPath !== null) {
                DocumentInstanceStorage::deletePdf($canonicalPath, (int) $request->company_id);
            }

            if ($signaturePath !== null) {
                DocumentRecipientSignatureStorage::delete($signaturePath, (int) $request->company_id);
            }

            throw $exception;
        } finally {
            if ($tempSignedPath !== null && file_exists($tempSignedPath)) {
                @unlink($tempSignedPath);
            }
        }
    }
}
