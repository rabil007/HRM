<?php

namespace App\Support\Payroll\CrewTimeline;

use App\Models\User;

final class CrewTimelinePagePermissions
{
    /**
     * @return array{
     *     view: bool,
     *     prepare: bool,
     *     submit: bool,
     *     approve: bool,
     *     return: bool,
     *     apply: bool,
     *     view_audit: bool
     * }
     */
    public static function for(?User $user): array
    {
        return [
            'view' => $user?->can('payroll.crew_timesheets.view') ?? false,
            'prepare' => $user?->can('payroll.crew_timesheets.prepare') ?? false,
            'submit' => $user?->can('payroll.crew_timesheets.submit') ?? false,
            'approve' => $user?->can('payroll.crew_timesheets.approve') ?? false,
            'return' => $user?->can('payroll.crew_timesheets.return') ?? false,
            'apply' => $user?->can('payroll.crew_timesheets.apply_approved') ?? false,
            'view_audit' => $user?->can('audit.view') ?? false,
        ];
    }
}
