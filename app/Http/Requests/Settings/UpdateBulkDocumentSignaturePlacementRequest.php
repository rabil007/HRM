<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBulkDocumentSignaturePlacementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('settings.application.update');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['required', 'integer', 'min:1'],
            'canvas_width' => ['required', 'numeric', 'min:1'],
            'canvas_height' => ['required', 'numeric', 'min:1'],
            'signature' => ['required', 'array'],
            'signature.left' => ['required', 'numeric', 'min:0'],
            'signature.top' => ['required', 'numeric', 'min:0'],
            'signature.width' => ['required', 'numeric', 'min:1'],
            'signature.height' => ['required', 'numeric', 'min:1'],
            'date' => ['required', 'array'],
            'date.left' => ['required', 'numeric', 'min:0'],
            'date.top' => ['required', 'numeric', 'min:0'],
            'date.width' => ['required', 'numeric', 'min:1'],
            'date.height' => ['required', 'numeric', 'min:1'],
        ];
    }
}
