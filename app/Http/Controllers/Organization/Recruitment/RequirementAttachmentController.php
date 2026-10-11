<?php

namespace App\Http\Controllers\Organization\Recruitment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Recruitment\StoreRequirementAttachmentRequest;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementAttachment;
use App\Support\Recruitment\RequirementAttachmentStorage;
use App\Support\Recruitment\RequirementWorkflowAuthorization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class RequirementAttachmentController extends Controller
{
    public function download(
        Request $request,
        RecruitmentRequirement $requirement,
        RecruitmentRequirementAttachment $attachment,
    ): StreamedResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless(
            (int) $requirement->company_id === $companyId
            && (int) $attachment->company_id === $companyId
            && (int) $attachment->recruitment_requirement_id === (int) $requirement->id,
            404,
        );

        $path = RequirementAttachmentStorage::validatedRelativePath(
            $attachment->file_path,
            $companyId,
            (int) $requirement->id,
        );

        abort_unless($path !== null && Storage::disk(RequirementAttachmentStorage::DISK)->exists($path), 404);

        $filename = $attachment->original_file_name ?: basename($path);

        return Storage::disk(RequirementAttachmentStorage::DISK)->download($path, $filename);
    }

    public function store(
        StoreRequirementAttachmentRequest $request,
        RecruitmentRequirement $requirement,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless((int) $requirement->company_id === $companyId, 404);

        $file = $request->file('attachment');
        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'attachment' => 'A valid attachment file is required.',
            ]);
        }

        $storedFilePath = null;

        try {
            DB::transaction(function () use ($requirement, $file, $request, $companyId, &$storedFilePath): void {
                $locked = RecruitmentRequirement::query()
                    ->where('company_id', $companyId)
                    ->whereKey($requirement->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                abort_unless(
                    RequirementWorkflowAuthorization::canPrepare($request->user(), $locked),
                    403,
                    'You are not authorized to modify attachments for this requirement.',
                );

                $storage = new RequirementAttachmentStorage;
                $fileMeta = $storage->store($locked, $file);
                $storedFilePath = $fileMeta['file_path'];

                RecruitmentRequirementAttachment::create([
                    'company_id' => $companyId,
                    'recruitment_requirement_id' => $locked->id,
                    'file_path' => $fileMeta['file_path'],
                    'original_file_name' => $fileMeta['original_file_name'],
                    'mime_type' => $fileMeta['mime_type'],
                    'file_size_bytes' => $fileMeta['file_size_bytes'],
                    'file_checksum' => $fileMeta['file_checksum'],
                    'uploaded_by' => (int) $request->user()->id,
                ]);

                activity('recruitment')
                    ->causedBy($request->user())
                    ->performedOn($locked)
                    ->withProperties([
                        'company_id' => $companyId,
                        'requirement_number' => $locked->requirement_number,
                        'file_name' => $fileMeta['original_file_name'],
                    ])
                    ->log("Attachment {$fileMeta['original_file_name']} uploaded.");
            });
        } catch (Throwable $exception) {
            if ($storedFilePath !== null) {
                Storage::disk(RequirementAttachmentStorage::DISK)->delete($storedFilePath);
            }

            throw $exception;
        }

        return redirect()->back()->with('success', 'Attachment uploaded successfully.');
    }

    public function destroy(
        Request $request,
        RecruitmentRequirement $requirement,
        RecruitmentRequirementAttachment $attachment,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless(
            (int) $requirement->company_id === $companyId
            && (int) $attachment->company_id === $companyId
            && (int) $attachment->recruitment_requirement_id === (int) $requirement->id,
            404,
        );

        abort_unless(
            RequirementWorkflowAuthorization::canPrepare($request->user(), $requirement),
            403,
            'You are not authorized to modify attachments for this requirement.',
        );

        $filePath = DB::transaction(function () use ($requirement, $attachment, $request, $companyId): ?string {
            $lockedRequirement = RecruitmentRequirement::query()
                ->where('company_id', $companyId)
                ->whereKey($requirement->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedAttachment = RecruitmentRequirementAttachment::query()
                ->where('company_id', $companyId)
                ->where('recruitment_requirement_id', $lockedRequirement->id)
                ->whereKey($attachment->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                RequirementWorkflowAuthorization::canPrepare($request->user(), $lockedRequirement),
                403,
                'You are not authorized to modify attachments for this requirement.',
            );

            $path = RequirementAttachmentStorage::validatedRelativePath(
                $lockedAttachment->file_path,
                $companyId,
                (int) $lockedRequirement->id,
            );

            $fileName = $lockedAttachment->original_file_name;
            $lockedAttachment->delete();

            activity('recruitment')
                ->causedBy($request->user())
                ->performedOn($lockedRequirement)
                ->withProperties([
                    'company_id' => $companyId,
                    'requirement_number' => $lockedRequirement->requirement_number,
                    'file_name' => $fileName,
                ])
                ->log("Attachment {$fileName} removed.");

            return $path;
        });

        if ($filePath !== null) {
            Storage::disk(RequirementAttachmentStorage::DISK)->delete($filePath);
        }

        return redirect()->back()->with('success', 'Attachment removed successfully.');
    }
}
