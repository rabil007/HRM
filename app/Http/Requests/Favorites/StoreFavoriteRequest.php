<?php

namespace App\Http\Requests\Favorites;

use App\Support\Navigation\NavigationDestinationCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFavoriteRequest extends FormRequest
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
            'key' => ['required', 'string', 'max:64', Rule::in(NavigationDestinationCatalog::keys())],
            'url' => ['prohibited'],
            'href' => ['prohibited'],
            'path' => ['prohibited'],
            'user_id' => ['prohibited'],
            'company_id' => ['prohibited'],
        ];
    }

    public function destinationKey(): string
    {
        return (string) $this->validated('key');
    }
}
