<?php

namespace App\Http\Requests\Organization\Payroll;

use Illuminate\Foundation\Http\FormRequest;

class RevertPayrollPeriodToProcessingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }
}
