<?php

namespace App\Http\Requests\Organization\Employee;

use Illuminate\Foundation\Http\FormRequest;

class BulkDestroyEmployeeSeaServicesRequest extends FormRequest
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
            'sea_service_ids' => ['required', 'array', 'min:1'],
            'sea_service_ids.*' => ['integer', 'distinct'],
        ];
    }
}
