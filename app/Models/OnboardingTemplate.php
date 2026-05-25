<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy model retained for historical migrations only.
 *
 * @deprecated Replaced by {@see EmployeeProfileTemplate}. Table is dropped by
 *             2026_05_25_103422_replace_onboarding_template_with_employee_profile_template_on_employees_table.
 */
class OnboardingTemplate extends Model
{
    protected $fillable = [
        'company_id',
        'name',
        'description',
        'is_default',
        'tasks',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'tasks' => 'array',
        ];
    }
}
