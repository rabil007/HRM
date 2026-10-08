<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

class SyncMissingLeaveBalancesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('reports.leave_balance.view') ?? false)
            && ($this->user()?->can('reports.leave_balance.sync') ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
