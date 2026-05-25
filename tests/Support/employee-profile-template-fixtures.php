<?php

use App\Models\Company;
use App\Models\EmployeeProfileTemplate;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateFieldRegistry;

function createEmployeeProfileTemplate(
    Company $company,
    string $name,
    ?array $configuration = null,
): EmployeeProfileTemplate {
    return EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => $name,
        'configuration_json' => $configuration ?? EmployeeProfileTemplateFieldRegistry::defaultConfiguration(),
    ]);
}

/**
 * @param  list<string>  $visibleKeys
 * @return array<string, mixed>
 */
function employeeProfileTemplateWithVisibleEmployeeFields(array $visibleKeys): array
{
    $configuration = EmployeeProfileTemplateFieldRegistry::defaultConfiguration();

    foreach ($configuration['fields']['employees'] as $fieldKey => $field) {
        $configuration['fields']['employees'][$fieldKey]['visible'] = in_array($fieldKey, $visibleKeys, true);
    }

    return $configuration;
}
