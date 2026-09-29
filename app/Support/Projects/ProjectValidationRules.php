<?php

namespace App\Support\Projects;

use App\Support\MasterData\ClientAssignmentRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

final class ProjectValidationRules
{
    /**
     * @return list<ValidationRule|string>
     */
    public static function titleRules(bool $unique = true): array
    {
        $rules = [
            'required',
            'string',
            'max:200',
        ];

        if ($unique) {
            $rules[] = self::titleUniqueRule();
        }

        return $rules;
    }

    public static function titleUniqueRule(): Unique
    {
        return Rule::unique('projects', 'title')->whereNull('deleted_at');
    }

    /**
     * @return list<string>
     */
    public static function isActiveRules(): array
    {
        return ['nullable', 'boolean'];
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public static function storeRules(bool $wantsJson = false): array
    {
        return [
            'title' => self::titleRules(unique: ! $wantsJson),
            'client_ids' => ['required', 'array', 'min:1'],
            'client_ids.*' => [
                ...ClientAssignmentRules::activeClientIdRules(required: true),
                'distinct',
            ],
            'is_active' => self::isActiveRules(),
        ];
    }
}
