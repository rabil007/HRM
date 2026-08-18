<?php

namespace App\Http\Requests\Favorites;

use Illuminate\Foundation\Http\FormRequest;

class DestroyFavoriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $destination = $this->route('destination');

        $this->merge([
            'destination' => is_string($destination) ? $destination : '',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'destination' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9._-]+$/'],
            'url' => ['prohibited'],
            'href' => ['prohibited'],
            'user_id' => ['prohibited'],
            'company_id' => ['prohibited'],
        ];
    }

    public function destinationKey(): string
    {
        return (string) $this->validated('destination');
    }
}
