<?php

namespace App\Http\Requests\Organization\Documents;

use App\Enums\DocumentRecipientAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentRecipientRequestRequest extends FormRequest
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
            'action' => ['required', 'string', Rule::in(DocumentRecipientAction::values())],
        ];
    }
}
