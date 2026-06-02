<?php

namespace App\Http\Requests\Settings\MasterData;

use Illuminate\Foundation\Http\FormRequest;

class UpdateApprovalLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $approvalLocationId = (int) $this->route('approval_location')?->id;

        return [
            'name' => ['required', 'string', 'max:120', "unique:approval_locations,name,{$approvalLocationId}"],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
