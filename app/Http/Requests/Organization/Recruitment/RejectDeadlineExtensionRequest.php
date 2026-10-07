<?php

namespace App\Http\Requests\Organization\Recruitment;

use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementDeadlineExtension;
use App\Support\Recruitment\RequirementWorkflowAuthorization;
use Illuminate\Foundation\Http\FormRequest;

class RejectDeadlineExtensionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $requirement = $this->route('requirement');

        if ($user === null || ! ($requirement instanceof RecruitmentRequirement)) {
            return false;
        }

        abort_unless(
            (int) $requirement->company_id === (int) $this->attributes->get('current_company_id'),
            404,
        );

        $extension = $this->route('deadline_extension');
        if ($extension instanceof RecruitmentRequirementDeadlineExtension) {
            abort_unless((int) $extension->company_id === (int) $requirement->company_id, 404);
            abort_unless((int) $extension->recruitment_requirement_id === (int) $requirement->id, 404);
        }

        return RequirementWorkflowAuthorization::canDecideDeadlineExtension($user, $requirement);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
