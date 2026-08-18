<?php

namespace App\Http\Requests\Search;

use Illuminate\Foundation\Http\FormRequest;

class GlobalSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $query = $this->query('q', $this->input('q'));

        $this->merge([
            'q' => is_string($query) ? trim($query) : '',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:80'],
            'company_id' => ['prohibited'],
            'category' => ['prohibited'],
            'categories' => ['prohibited'],
        ];
    }

    public function queryString(): string
    {
        return (string) ($this->validated('q') ?? '');
    }
}
