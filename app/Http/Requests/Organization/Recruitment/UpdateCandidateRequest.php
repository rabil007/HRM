<?php

namespace App\Http\Requests\Organization\Recruitment;

use App\Enums\Recruitment\CandidateSource;
use App\Support\Recruitment\Candidates\CandidateCvStorage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('recruitment.candidates.update');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'email' => ['nullable', 'email', 'max:200', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:50', 'required_without:email'],
            'nationality_id' => ['nullable', 'integer', 'exists:countries,id'],
            'source' => ['nullable', 'string', Rule::in(CandidateSource::values())],
            'notes' => ['nullable', 'string', 'max:5000'],
            'cv' => [
                'nullable',
                'file',
                'max:'.CandidateCvStorage::MAX_SIZE_KB,
                'mimes:'.implode(',', CandidateCvStorage::ALLOWED_MIMES),
            ],
            'remove_cv' => ['sometimes', 'boolean'],
            'lock_version' => ['nullable', 'integer', 'min:0'],
            'ignore_duplicate_warning' => ['sometimes', 'boolean'],
        ];
    }
}
