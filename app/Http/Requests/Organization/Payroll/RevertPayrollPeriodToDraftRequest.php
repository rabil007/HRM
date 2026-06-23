<?php

namespace App\Http\Requests\Organization\Payroll;

use Illuminate\Foundation\Http\FormRequest;

class RevertPayrollPeriodToDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('payroll.periods.revert_to_draft');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
