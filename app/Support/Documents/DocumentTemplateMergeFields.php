<?php

namespace App\Support\Documents;

use App\Models\Employee;

final class DocumentTemplateMergeFields
{
    /**
     * @return list<array{key: string, label: string, category: string, sample: string}>
     */
    public static function definitions(): array
    {
        return [
            // Employee Identity
            [
                'key' => '{{employee_name}}',
                'label' => 'Employee Full Name',
                'category' => 'Employee',
                'sample' => 'Jane Smith',
            ],
            [
                'key' => '{{employee_no}}',
                'label' => 'Employee Number',
                'category' => 'Employee',
                'sample' => 'EMP-1042',
            ],
            [
                'key' => '{{first_name}}',
                'label' => 'First Name',
                'category' => 'Employee',
                'sample' => 'Jane',
            ],
            [
                'key' => '{{last_name}}',
                'label' => 'Last Name',
                'category' => 'Employee',
                'sample' => 'Smith',
            ],
            [
                'key' => '{{email}}',
                'label' => 'Email Address',
                'category' => 'Employee',
                'sample' => 'jane.smith@example.com',
            ],
            [
                'key' => '{{phone}}',
                'label' => 'Phone Number',
                'category' => 'Employee',
                'sample' => '+971 50 123 4567',
            ],
            [
                'key' => '{{gender}}',
                'label' => 'Gender',
                'category' => 'Employee',
                'sample' => 'Female',
            ],
            [
                'key' => '{{joining_date}}',
                'label' => 'Joining Date',
                'category' => 'Employee',
                'sample' => '15 Jan 2022',
            ],

            // Organization
            [
                'key' => '{{company_name}}',
                'label' => 'Company Name',
                'category' => 'Organization',
                'sample' => 'Overseas Marine Services LLC',
            ],
            [
                'key' => '{{department_name}}',
                'label' => 'Department Name',
                'category' => 'Organization',
                'sample' => 'Marine Operations',
            ],
            [
                'key' => '{{position_name}}',
                'label' => 'Position Title',
                'category' => 'Organization',
                'sample' => 'Chief Engineer',
            ],
            [
                'key' => '{{branch_name}}',
                'label' => 'Branch Name',
                'category' => 'Organization',
                'sample' => 'Dubai Main Office',
            ],

            // System / Date
            [
                'key' => '{{today}}',
                'label' => "Today's Date",
                'category' => 'System',
                'sample' => now()->format('d M Y'),
            ],
            [
                'key' => '{{current_year}}',
                'label' => 'Current Year',
                'category' => 'System',
                'sample' => now()->format('Y'),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function allowedKeys(): array
    {
        return array_column(self::definitions(), 'key');
    }

    /**
     * Extract any {{placeholder}} tokens from content and return any that are not in the allowed list.
     *
     * @return list<string>
     */
    public static function findUnsupported(string $content): array
    {
        preg_match_all('/\{\{[^}]+\}\}/', $content, $matches);

        $found = array_unique($matches[0] ?? []);
        $allowed = self::allowedKeys();

        return array_values(array_diff($found, $allowed));
    }

    /**
     * @return array<string, string>
     */
    public static function sampleValues(?string $companyName = null): array
    {
        $values = [];
        foreach (self::definitions() as $definition) {
            $values[$definition['key']] = $definition['sample'];
        }

        if ($companyName !== null && $companyName !== '') {
            $values['{{company_name}}'] = $companyName;
        }

        return $values;
    }

    /**
     * Resolve merge fields using a real Employee record.
     *
     * @return array<string, string>
     */
    public static function valuesForEmployee(Employee $employee): array
    {
        $employee->loadMissing(['company', 'department', 'position', 'branch', 'genderRef']);

        $fullName = trim((string) $employee->name);
        $firstName = (string) ($employee->first_name ?: explode(' ', $fullName)[0] ?: '');
        $lastName = (string) ($employee->last_name ?: (str_contains($fullName, ' ') ? substr($fullName, strpos($fullName, ' ') + 1) : ''));

        $joiningDate = $employee->hire_date ? $employee->hire_date->format('d M Y') : '';

        return [
            '{{employee_name}}' => $fullName,
            '{{employee_no}}' => (string) ($employee->employee_no ?? ''),
            '{{first_name}}' => $firstName,
            '{{last_name}}' => $lastName,
            '{{email}}' => (string) ($employee->work_email ?: $employee->personal_email ?: ''),
            '{{phone}}' => (string) ($employee->phone ?? ''),
            '{{gender}}' => (string) ($employee->genderRef?->name ?? $employee->genderRef?->title ?? ''),
            '{{joining_date}}' => $joiningDate,
            '{{company_name}}' => (string) ($employee->company?->name ?? ''),
            '{{department_name}}' => (string) ($employee->department?->name ?? ''),
            '{{position_name}}' => (string) ($employee->position?->title ?? $employee->position?->name ?? ''),
            '{{branch_name}}' => (string) ($employee->branch?->name ?? ''),
            '{{today}}' => now()->format('d M Y'),
            '{{current_year}}' => now()->format('Y'),
        ];
    }

    /**
     * Replace allowed placeholders in content with provided values.
     *
     * @param  array<string, string>  $values
     */
    public static function apply(string $content, array $values): string
    {
        return strtr($content, $values);
    }
}
