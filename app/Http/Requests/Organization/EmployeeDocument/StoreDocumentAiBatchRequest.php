<?php

namespace App\Http\Requests\Organization\EmployeeDocument;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreDocumentAiBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('documents.ai.use') ?? false;
    }

    public function rules(): array
    {
        return [
            'batch_request_id' => ['required', 'uuid'],
            // MIME allow-list is enforced per-file in the controller so unsupported
            // files can be rejected individually without failing the whole batch.
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => ['required', 'file', 'max:10240'],
            'draft_ids' => ['required', 'array'],
            'draft_ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $files = $this->file('files', []);
            $draftIds = $this->input('draft_ids', []);

            if (! is_array($files) || ! is_array($draftIds)) {
                return;
            }

            if (count($files) !== count($draftIds)) {
                $validator->errors()->add(
                    'draft_ids',
                    'Each uploaded file must include a matching draft id.',
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('company_id');
        $this->request->remove('employee_id');
    }
}
