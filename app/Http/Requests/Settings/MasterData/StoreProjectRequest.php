<?php

namespace App\Http\Requests\Settings\MasterData;

use App\Support\Projects\ProjectValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('title')) {
            $this->merge([
                'title' => trim((string) $this->input('title')),
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ProjectValidationRules::storeRules($this->wantsJson());
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $clientErrors = collect($validator->errors()->messages())
                ->filter(fn (array $messages, string $key): bool => $key === 'client_ids' || str_starts_with($key, 'client_ids.'))
                ->flatten()
                ->filter()
                ->values();

            if ($clientErrors->isEmpty()) {
                return;
            }

            $firstClientError = (string) $clientErrors->first();

            if (! $validator->errors()->has('client_ids')) {
                $validator->errors()->add('client_ids', $firstClientError);
            }

        });
    }
}
