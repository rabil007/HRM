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
        $rectRules = [
            'left' => ['required', 'numeric', 'min:0'],
            'top' => ['required', 'numeric', 'min:0'],
            'width' => ['required', 'numeric', 'min:1'],
            'height' => ['required', 'numeric', 'min:1'],
        ];

        return [
            'page' => ['required', 'integer', 'min:1'],
            'canvas_width' => ['required', 'numeric', 'min:1'],
            'canvas_height' => ['required', 'numeric', 'min:1'],
            'signature' => ['required', 'array'],
            'signature.left' => $rectRules['left'],
            'signature.top' => $rectRules['top'],
            'signature.width' => $rectRules['width'],
            'signature.height' => $rectRules['height'],
            'date' => ['required', 'array'],
            'date.left' => $rectRules['left'],
            'date.top' => $rectRules['top'],
            'date.width' => $rectRules['width'],
            'date.height' => $rectRules['height'],
            'signature_ar' => ['required', 'array'],
            'signature_ar.left' => $rectRules['left'],
            'signature_ar.top' => $rectRules['top'],
            'signature_ar.width' => $rectRules['width'],
            'signature_ar.height' => $rectRules['height'],
            'date_ar' => ['required', 'array'],
            'date_ar.left' => $rectRules['left'],
            'date_ar.top' => $rectRules['top'],
            'date_ar.width' => $rectRules['width'],
            'date_ar.height' => $rectRules['height'],
        ];
    }
}
