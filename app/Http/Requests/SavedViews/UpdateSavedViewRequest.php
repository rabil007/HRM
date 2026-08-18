<?php

namespace App\Http\Requests\SavedViews;

use App\Models\SavedView;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSavedViewRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $view = $this->route('savedView');
        $companyId = (int) $this->attributes->get('current_company_id');

        if ($user === null || ! $view instanceof SavedView) {
            return false;
        }

        if ($view->user_id !== $user->id || $view->company_id !== $companyId) {
            abort(404);
        }

        return $view->page_key->userCanAccess($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $view = $this->route('savedView');
        $user = $this->user();
        $companyId = (int) $this->attributes->get('current_company_id');

        $uniqueName = Rule::unique('saved_views', 'name')->where(
            fn ($query) => $query
                ->where('user_id', $user?->id)
                ->where('company_id', $companyId)
                ->where('page_key', $view instanceof SavedView ? $view->page_key : ''),
        );

        if ($view instanceof SavedView) {
            $uniqueName->ignore($view->id);
        }

        return [
            'name' => ['sometimes', 'required', 'string', 'max:'.SavedView::NAME_MAX_LENGTH, $uniqueName],
            'is_default' => ['sometimes', 'boolean'],
            'filters' => ['prohibited'],
            'page_key' => ['prohibited'],
            'url' => ['prohibited'],
            'href' => ['prohibited'],
            'path' => ['prohibited'],
            'query' => ['prohibited'],
            'user_id' => ['prohibited'],
            'company_id' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if (! $this->exists('name') && ! $this->exists('is_default')) {
                    $validator->errors()->add('name', 'Provide a name or default flag to update.');
                }
            },
        ];
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
