<?php

use App\Models\OnboardingTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** @var list<string> */
    private array $bankKeys = ['bank_id', 'iban', 'account_name'];

    public function up(): void
    {
        OnboardingTemplate::query()->each(function (OnboardingTemplate $template): void {
            $tasks = $template->tasks;

            if (! is_array($tasks) || ($tasks['version'] ?? null) !== 1) {
                return;
            }

            $template->tasks = $this->convertVersion1ToVersion2($tasks);
            $template->save();
        });
    }

    /**
     * @param  array<string, mixed>  $tasks
     * @return array<string, mixed>
     */
    private function convertVersion1ToVersion2(array $tasks): array
    {
        $stages = is_array($tasks['stages'] ?? null) ? $tasks['stages'] : [];
        $modules = is_array($tasks['modules'] ?? null) ? $tasks['modules'] : [];

        $profileFields = is_array($modules['profile']['required_fields'] ?? null)
            ? $modules['profile']['required_fields']
            : [];
        $contractFields = is_array($modules['contract']['required_fields'] ?? null)
            ? $modules['contract']['required_fields']
            : [];
        $requiredDocs = is_array($modules['documents']['required_docs'] ?? null)
            ? $modules['documents']['required_docs']
            : [];

        $bankKeySet = array_fill_keys($this->bankKeys, true);
        $profileKeys = $this->normalizeKeys($profileFields);
        $employeeKeys = array_values(array_filter($profileKeys, fn (string $key) => ! isset($bankKeySet[$key])));
        $bankKeys = array_values(array_filter($profileKeys, fn (string $key) => isset($bankKeySet[$key])));
        $contractKeys = $this->normalizeKeys($contractFields);

        $v2Stages = [];

        foreach ($stages as $stage) {
            if (! is_array($stage)) {
                continue;
            }

            $mods = is_array($stage['modules'] ?? null) ? $stage['modules'] : [];
            $key = trim((string) ($stage['key'] ?? ''));
            $label = trim((string) ($stage['label'] ?? $key));

            $v2Stages[] = [
                'key' => $key,
                'label' => $label !== '' ? $label : $key,
                'employee_fields' => in_array('profile', $mods, true)
                    ? $this->mapKeysToFieldRequirements($employeeKeys)
                    : [],
                'bank_account_fields' => in_array('profile', $mods, true)
                    ? $this->mapKeysToFieldRequirements($bankKeys)
                    : [],
                'contract_fields' => in_array('contract', $mods, true)
                    ? $this->mapKeysToFieldRequirements($contractKeys)
                    : [],
                'sea_service_fields' => [],
                'vaccination_fields' => [],
                'training_fields' => [],
                'documents' => in_array('documents', $mods, true)
                    ? $this->mapDocumentsToVersion2($requiredDocs)
                    : [],
            ];
        }

        return [
            'version' => 2,
            'stages' => $v2Stages,
        ];
    }

    /**
     * @param  list<mixed>  $fields
     * @return list<string>
     */
    private function normalizeKeys(array $fields): array
    {
        $keys = [];

        foreach ($fields as $field) {
            if (is_string($field)) {
                $key = trim($field);

                if ($key !== '') {
                    $keys[] = $key;
                }

                continue;
            }

            if (is_array($field)) {
                $key = trim((string) ($field['key'] ?? $field['type'] ?? ''));

                if ($key !== '') {
                    $keys[] = $key;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  list<string>  $keys
     * @return list<array{key: string, required: bool}>
     */
    private function mapKeysToFieldRequirements(array $keys): array
    {
        return array_map(
            fn (string $key): array => ['key' => $key, 'required' => true],
            $keys,
        );
    }

    /**
     * @param  list<mixed>  $docs
     * @return list<array<string, mixed>>
     */
    private function mapDocumentsToVersion2(array $docs): array
    {
        $mapped = [];

        foreach ($docs as $doc) {
            if (is_string($doc)) {
                $type = trim($doc);

                if ($type !== '') {
                    $mapped[] = [
                        'type' => $type,
                        'min' => 1,
                        'ask_issue_date' => false,
                        'ask_expiry_date' => false,
                        'ask_document_number' => false,
                    ];
                }

                continue;
            }

            if (! is_array($doc)) {
                continue;
            }

            $type = trim((string) ($doc['type'] ?? ''));

            if ($type === '') {
                continue;
            }

            $mapped[] = [
                'type' => $type,
                'min' => max(1, (int) ($doc['min'] ?? 1)),
                'ask_issue_date' => (bool) ($doc['ask_issue_date'] ?? false),
                'ask_expiry_date' => (bool) ($doc['ask_expiry_date'] ?? false),
                'ask_document_number' => (bool) ($doc['ask_document_number'] ?? false),
            ];
        }

        return $mapped;
    }
};
