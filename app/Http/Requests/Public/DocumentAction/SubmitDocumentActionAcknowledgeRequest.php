<?php

namespace App\Http\Requests\Public\DocumentAction;

use Illuminate\Foundation\Http\FormRequest;

class SubmitDocumentActionAcknowledgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'acknowledgement' => ['accepted'],
        ];
    }

    protected function passedValidation(): void
    {
        $this->merge([
            'acknowledgement' => $this->boolean('acknowledgement'),
        ]);
    }
}
