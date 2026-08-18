<?php

namespace App\Http\Requests\SavedViews;

use App\Enums\SavedViewPage;
use App\Models\SavedView;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSavedViewRequest extends FormRequest
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
        $user = $this->user();
        $companyId = (int) $this->attributes->get('current_company_id');
        $pageKey = (string) $this->input('page_key', '');

        return [
            'page_key' => ['required', 'string', Rule::enum(SavedViewPage::class)],
            'name' => [
                'required',
                'string',
                'max:'.SavedView::NAME_MAX_LENGTH,
                Rule::unique('saved_views', 'name')->where(
                    fn ($query) => $query
                        ->where('user_id', $user?->id)
                        ->where('company_id', $companyId)
                        ->where('page_key', $pageKey),
                ),
            ],
            'filters' => ['required', 'array'],
            'is_default' => ['sometimes', 'boolean'],
            'url' => ['prohibited'],
            'href' => ['prohibited'],
            'path' => ['prohibited'],
            'query' => ['prohibited'],
            'user_id' => ['prohibited'],
            'company_id' => ['prohibited'],
        ];
    }

    public function page(): SavedViewPage
    {
        return SavedViewPage::from((string) $this->validated('page_key'));
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        /** @var array<string, mixed> $filters */
        $filters = $this->validated('filters');

        return $filters;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name') && is_string($this->input('name'))) {
            $this->merge([
                'name' => trim($this->input('name')),
            ]);
        }
    }
}
