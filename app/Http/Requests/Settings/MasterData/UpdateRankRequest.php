<?php

namespace App\Http\Requests\Settings\MasterData;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRankRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rankId = (int) $this->route('rank')?->id;

        return [
            'name' => ['required', 'string', 'max:120', "unique:ranks,name,{$rankId}"],
            'is_active' => ['nullable', 'boolean'],
            'max_tour_of_duty_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ];
    }
}
