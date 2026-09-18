<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    public const string SCOPE_ALL = 'all';

    public const string SCOPE_SELECTED_DEPARTMENTS = 'selected_departments';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'guard_name',
        'company_id',
        'employee_visibility_scope',
    ];

    /**
     * @return BelongsToMany<Department, $this>
     */
    public function employeeVisibilityDepartments(): BelongsToMany
    {
        return $this->belongsToMany(
            Department::class,
            'role_employee_visibility_departments',
            'role_id',
            'department_id'
        )->withTimestamps();
    }
}
