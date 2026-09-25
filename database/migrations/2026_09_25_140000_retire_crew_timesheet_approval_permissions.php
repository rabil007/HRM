<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const RETIRED_PERMISSIONS = [
        'payroll.crew_timesheets.apply_approved',
        'payroll.crew_timesheets.approve',
        'payroll.crew_timesheets.return',
        'payroll.crew_timesheets.skip_timeline',
        'payroll.crew_timesheets.submit',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', self::RETIRED_PERMISSIONS)
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Retired Crew Timesheet approval-workflow permissions are not restored.
    }
};
