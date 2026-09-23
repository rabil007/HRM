<?php

namespace App\Http\Controllers\Organization\Recruitment;

use App\Http\Controllers\Controller;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementAttachment;
use App\Support\Recruitment\RequirementAttachmentStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        abort_unless($requirement->status->isEditable(), 422, 'Cannot remove attachments from a closed requirement.');

        $storage = new RequirementAttachmentStorage;
        $storage->delete($attachment);

        $fileName = $attachment->original_file_name;
        $attachment->delete();

        activity('recruitment')
            ->causedBy($request->user())
            ->performedOn($requirement)
            ->withProperties([
                'company_id' => $companyId,
                'requirement_number' => $requirement->requirement_number,
                'file_name' => $fileName,
            ])
            ->log("Attachment {$fileName} removed.");

        return redirect()->back()->with('success', 'Attachment removed successfully.');
    }
}
