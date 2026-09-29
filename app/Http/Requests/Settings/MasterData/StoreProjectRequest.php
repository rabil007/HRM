<?php

namespace App\Http\Requests\Settings\MasterData;

use App\Support\MasterData\ClientAssignmentRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('client_ids') && $this->has('client_id')) {
            $clientId = $this->input('client_id');

            $this->merge([
                'client_ids' => $clientId !== null && $clientId !== '' ? [$clientId] : [],
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $titleRules = [
            'required',
            'string',
            'max:200',
        ];

        if (! $this->wantsJson()) {
            $titleRules[] = Rule::unique('projects', 'title')->whereNull('deleted_at');
        }

        return [
            // Uniqueness remains global on title (DB: uq_projects_title) until a
            // soft-delete-safe (client_id, title) unique index can be introduced.
            'title' => $titleRules,
            'client_ids' => ['required', 'array', 'min:1'],
            'client_ids.*' => [
                ...ClientAssignmentRules::activeClientIdRules(required: true),
                'distinct',
            ],
            'client_id' => ['nullable'],
            'is_active' => ['nullable', 'boolean'],
        ];
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

            if (! $validator->errors()->has('client_id')) {
                $validator->errors()->add('client_id', $firstClientError);
            }
        });
    }
}
