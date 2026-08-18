<?php

namespace App\Http\Requests\RecentItems;

use Illuminate\Foundation\Http\FormRequest;

class ListRecentItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['prohibited'],
            'company_id' => ['prohibited'],
            'record_type' => ['prohibited'],
            'record_id' => ['prohibited'],
        ];
    }
}
