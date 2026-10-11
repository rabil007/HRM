<?php

namespace App\Http\Requests\Organization\Recruitment;

use App\Models\RecruitmentRequirement;
use App\Support\Recruitment\RequirementAttachmentStorage;
use App\Support\Recruitment\RequirementWorkflowAuthorization;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequirementAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $requirement = $this->route('requirement');
        $companyId = (int) $this->attributes->get('current_company_id');

        if (! $requirement instanceof RecruitmentRequirement || (int) $requirement->company_id !== $companyId) {
            abort(404);
        }

        $user = $this->user();

        return $user !== null && RequirementWorkflowAuthorization::canPrepare($user, $requirement);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'attachment' => [
                'required',
                'file',
                'mimes:'.implode(',', RequirementAttachmentStorage::ALLOWED_MIMES),
                'max:'.RequirementAttachmentStorage::MAX_SIZE_KB,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'attachment.required' => 'Please select a file to upload.',
            'attachment.file' => 'The uploaded file is invalid.',
            'attachment.mimes' => 'The attachment must be a file of type: '.implode(', ', RequirementAttachmentStorage::ALLOWED_MIMES).'.',
            'attachment.max' => 'The attachment may not be greater than 20MB.',
        ];
    }
}
