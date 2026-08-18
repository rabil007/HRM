<?php

namespace App\Http\Requests\SavedViews;

use App\Models\SavedView;
use Illuminate\Foundation\Http\FormRequest;

class DestroySavedViewRequest extends FormRequest
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
        return [
            'url' => ['prohibited'],
            'href' => ['prohibited'],
            'user_id' => ['prohibited'],
            'company_id' => ['prohibited'],
        ];
    }
}
