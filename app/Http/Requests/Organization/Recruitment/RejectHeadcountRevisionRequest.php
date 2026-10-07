<?php

namespace App\Http\Requests\Organization\Recruitment;

use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementHeadcountRevision;
use App\Support\Recruitment\RequirementWorkflowAuthorization;
use Illuminate\Foundation\Http\FormRequest;

class RejectHeadcountRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $requirement = $this->route('requirement');
        $revision = $this->route('headcount_revision');

        if (
            $user === null
            || ! ($requirement instanceof RecruitmentRequirement)
            || ! ($revision instanceof RecruitmentRequirementHeadcountRevision)
        ) {
            return false;
        }

        abort_unless(
            (int) $requirement->company_id === (int) $this->attributes->get('current_company_id'),
            404,
        );
        abort_unless((int) $revision->company_id === (int) $requirement->company_id, 404);
        abort_unless((int) $revision->recruitment_requirement_id === (int) $requirement->id, 404);

        return RequirementWorkflowAuthorization::canDecideHeadcountRevision($user, $requirement, $revision);
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
