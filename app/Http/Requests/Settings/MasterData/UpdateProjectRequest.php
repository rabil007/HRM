<?php

namespace App\Http\Requests\Settings\MasterData;

use App\Models\Client;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProjectRequest extends FormRequest
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
        /** @var Project|null $project */
        $project = $this->route('project');
        $existingClientIds = $project instanceof Project
            ? $project->clients()
                ->pluck('clients.id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all()
            : [];

        if ($existingClientIds === [] && $project?->client_id !== null) {
            $existingClientIds[] = (int) $project->client_id;
        }

        $clientIdsRules = $existingClientIds === []
            ? ['nullable', 'array']
            : ['required', 'array', 'min:1'];

        return [
            // Uniqueness remains global on title (DB: uq_projects_title) until a
            // soft-delete-safe (client_id, title) unique index can be introduced.
            'title' => [
                'required',
                'string',
                'max:200',
                Rule::unique('projects', 'title')
                    ->ignore($project)
                    ->whereNull('deleted_at'),
            ],
            'client_ids' => $clientIdsRules,
            'client_ids.*' => [
                'required',
                'integer',
                'distinct',
                function (string $attribute, mixed $value, \Closure $fail) use ($existingClientIds): void {
                    $clientId = (int) $value;
                    $client = Client::query()->find($clientId);

                    if ($client === null) {
                        $fail('The selected client is invalid.');

                        return;
                    }

                    if (in_array($clientId, $existingClientIds, true)) {
                        return;
                    }

                    if (! $client->is_active) {
                        $fail('The selected client is inactive.');
                    }
                },
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
