<?php

namespace App\Support\Employees;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Country;
use App\Models\Department;
use App\Models\Gender;
use App\Models\Position;
use App\Models\Project;
use App\Models\Religion;

final class EmployeeImportTemplateOptions
{
    /**
     * Import column name => ordered list of allowed display values for Excel dropdowns.
     *
     * @return array<string, list<string>>
     */
    public static function forCompany(int $companyId): array
    {
        return [
            'branch' => Branch::query()
                ->where('company_id', $companyId)
                ->orderBy('name')
                ->pluck('name')
                ->map(fn ($name) => (string) $name)
                ->values()
                ->all(),
            'department' => Department::query()
                ->where('company_id', $companyId)
                ->orderBy('name')
                ->pluck('name')
                ->map(fn ($name) => (string) $name)
                ->values()
                ->all(),
            'position' => Position::query()
                ->where('company_id', $companyId)
                ->orderBy('title')
                ->pluck('title')
                ->map(fn ($title) => (string) $title)
                ->values()
                ->all(),
            'project' => Project::query()
                ->where('is_active', true)
                ->orderBy('title')
                ->pluck('title')
                ->map(fn ($title) => (string) $title)
                ->values()
                ->all(),
            'client' => Client::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->pluck('name')
                ->map(fn ($name) => (string) $name)
                ->values()
                ->all(),
            'gender' => Gender::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->pluck('name')
                ->map(fn ($name) => (string) $name)
                ->values()
                ->all(),
            'religion' => Religion::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->pluck('name')
                ->map(fn ($name) => (string) $name)
                ->values()
                ->all(),
            'nationality' => Country::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->pluck('name')
                ->map(fn ($name) => (string) $name)
                ->values()
                ->all(),
            'marital_status' => ['single', 'married', 'divorced', 'widowed'],
            'status' => ['active', 'inactive', 'on_leave', 'terminated'],
        ];
    }
}
