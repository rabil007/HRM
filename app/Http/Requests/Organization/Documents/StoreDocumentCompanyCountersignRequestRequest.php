<?php

namespace App\Http\Requests\Organization\Documents;

use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentCompanyCountersignRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('documents.recipient-requests.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'recipient_user_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
