<?php

namespace App\Support\EmployeeProfileTemplates;

use App\Models\EmployeeProfileTemplate;

final class EmployeeProfileTemplateResolver
{
    /**
     * @return array{
     *     version: int,
     *     tabs: array<string, array{visible: bool}>,
     *     fields: array<string, array<string, array{visible: bool, required: bool}>>,
     *     personal_field_keys: list<string>,
     *     employee_tabs: array<string, bool|list<string>|null>
     * }
     */
    public static function defaults(): array
    {
        return self::resolveConfiguration(EmployeeProfileTemplateFieldRegistry::defaultConfiguration());
    }

    /**
     * @return array{
     *     version: int,
     *     tabs: array<string, array{visible: bool}>,
     *     fields: array<string, array<string, array{visible: bool, required: bool}>>,
     *     personal_field_keys: list<string>,
     *     employee_tabs: array<string, bool|list<string>|null>
     * }
     */
    public static function resolve(?EmployeeProfileTemplate $template): array
    {
        if ($template === null) {
            return self::defaults();
        }

        $stored = is_array($template->configuration_json) ? $template->configuration_json : [];

        return self::resolveConfiguration(self::mergeWithDefaults($stored));
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array{version: int, tabs: array<string, array{visible: bool}>, fields: array<string, array<string, array{visible: bool, required: bool}>>}
     */
    public static function mergeWithDefaults(array $stored): array
    {
        $defaults = EmployeeProfileTemplateFieldRegistry::defaultConfiguration();

        $tabs = $defaults['tabs'];
        $storedTabs = is_array($stored['tabs'] ?? null) ? $stored['tabs'] : [];
        foreach (EmployeeProfileTemplateFieldRegistry::TAB_ORDER as $tabKey) {
            $tabConfig = $storedTabs[$tabKey] ?? null;
            if (is_array($tabConfig) && array_key_exists('visible', $tabConfig)) {
                $tabs[$tabKey]['visible'] = (bool) $tabConfig['visible'];
            }
        }
        $tabs['personal']['visible'] = true;

        $fields = $defaults['fields'];
        $storedFields = self::normalizeLegacyRankIdFields(
            is_array($stored['fields'] ?? null) ? $stored['fields'] : [],
        );

        foreach (EmployeeProfileTemplateFieldRegistry::fieldsByTable() as $table => $tableFieldLabels) {
            foreach (array_keys($tableFieldLabels) as $fieldKey) {
                $fieldConfig = $storedFields[$table][$fieldKey] ?? null;
                if (! is_array($fieldConfig)) {
                    continue;
                }
                if (array_key_exists('visible', $fieldConfig)) {
                    $fields[$table][$fieldKey]['visible'] = (bool) $fieldConfig['visible'];
                }
                if (array_key_exists('required', $fieldConfig)) {
                    $fields[$table][$fieldKey]['required'] = (bool) $fieldConfig['required'];
                }
            }
        }

        self::forceLockedEmployeeIdentityFields($fields);

        return [
            'version' => (int) ($stored['version'] ?? 1),
            'tabs' => $tabs,
            'fields' => $fields,
        ];
    }

    /**
     * @param  array<string, array<string, array{visible: bool, required: bool}>>  $fields
     */
    private static function forceLockedEmployeeIdentityFields(array &$fields): void
    {
        foreach (EmployeeProfileTemplateRequestRules::LOCKED_REQUIRED_FIELDS_BY_TABLE as $table => $lockedKeys) {
            foreach ($lockedKeys as $fieldKey) {
                if (! isset($fields[$table][$fieldKey]) || ! is_array($fields[$table][$fieldKey])) {
                    continue;
                }

                $fields[$table][$fieldKey]['visible'] = true;
                $fields[$table][$fieldKey]['required'] = true;
            }
        }
    }

    /**
     * Map legacy stored rank_id field config onto canonical position_id.
     *
     * Rules:
     * - rank_id only → copy onto position_id
     * - both present → position_id wins
     * - rank_id is never re-exposed after resolution
     *
     * @param  array<string, mixed>  $storedFields
     * @return array<string, mixed>
     */
    private static function normalizeLegacyRankIdFields(array $storedFields): array
    {
        foreach (['employees', 'employee_sea_services'] as $table) {
            if (! isset($storedFields[$table]) || ! is_array($storedFields[$table])) {
                continue;
            }

            $legacyRankConfig = $storedFields[$table]['rank_id'] ?? null;
            $hasCanonicalPosition = isset($storedFields[$table]['position_id'])
                && is_array($storedFields[$table]['position_id']);

            if (is_array($legacyRankConfig) && ! $hasCanonicalPosition) {
                $storedFields[$table]['position_id'] = $legacyRankConfig;
            }

            unset($storedFields[$table]['rank_id']);
        }

        return $storedFields;
    }

    /**
     * @param  array{version: int, tabs: array<string, array{visible: bool}>, fields: array<string, array<string, array{visible: bool, required: bool}>>}  $configuration
     * @return array{
     *     version: int,
     *     tabs: array<string, array{visible: bool}>,
     *     fields: array<string, array<string, array{visible: bool, required: bool}>>,
     *     personal_field_keys: list<string>,
     *     employee_tabs: array<string, bool|list<string>|null>
     * }
     */
    public static function resolveConfiguration(array $configuration): array
    {
        $personalFieldKeys = [];
        foreach ($configuration['fields']['employees'] ?? [] as $key => $field) {
            if (($field['visible'] ?? false) === true) {
                $personalFieldKeys[] = $key;
            }
        }

        $employeeTabs = [
            'personal' => true,
            'contract' => (bool) ($configuration['tabs']['contract']['visible'] ?? false),
            'bank' => (bool) ($configuration['tabs']['bank']['visible'] ?? false),
            'education' => (bool) ($configuration['tabs']['education']['visible'] ?? false),
            'work_experience' => (bool) ($configuration['tabs']['work_experience']['visible'] ?? false),
            'languages' => (bool) ($configuration['tabs']['languages']['visible'] ?? false),
            'training' => (bool) ($configuration['tabs']['training']['visible'] ?? false),
            'sea_service' => (bool) ($configuration['tabs']['sea_service']['visible'] ?? false),
            'documents' => (bool) ($configuration['tabs']['documents']['visible'] ?? false),
            'vaccination' => (bool) ($configuration['tabs']['vaccinations']['visible'] ?? false),
            'profile_fields' => $personalFieldKeys === [] ? null : array_values(array_unique($personalFieldKeys)),
            'template_fields' => $configuration['fields'],
        ];

        return [
            'version' => $configuration['version'],
            'tabs' => $configuration['tabs'],
            'fields' => $configuration['fields'],
            'personal_field_keys' => array_values(array_unique($personalFieldKeys)),
            'employee_tabs' => $employeeTabs,
        ];
    }

    public static function employeeFieldVisible(?EmployeeProfileTemplate $template, string $fieldKey): bool
    {
        $profileFields = self::resolve($template)['employee_tabs']['profile_fields'] ?? null;

        if ($profileFields === null) {
            return true;
        }

        return in_array($fieldKey, $profileFields, true);
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    public static function normalizeForStorage(array $configuration): array
    {
        $merged = self::mergeWithDefaults($configuration);
        $merged['version'] = 1;
        $merged['tabs']['personal']['visible'] = true;
        self::forceLockedEmployeeIdentityFields($merged['fields']);

        return $merged;
    }
}
