<?php

namespace App\Http\Requests\Public\DocumentAction;

use Illuminate\Foundation\Http\FormRequest;

class SubmitDocumentActionSignRequest extends FormRequest
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
            'signed_name' => ['required', 'string', 'max:255'],
            'signature_data' => ['required', 'string', 'max:500000'],
            'consent' => ['accepted'],
        ];
    }

    protected function passedValidation(): void
    {
        $this->merge([
            'consent' => $this->boolean('consent'),
        ]);
    }
}
