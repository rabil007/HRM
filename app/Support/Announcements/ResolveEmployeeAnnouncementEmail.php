<?php

namespace App\Support\Announcements;

use App\Models\Employee;

final class ResolveEmployeeAnnouncementEmail
{
    public static function for(Employee $employee): ?string
    {
        if (filled($employee->work_email)) {
            return (string) $employee->work_email;
        }

        if (filled($employee->personal_email)) {
            return (string) $employee->personal_email;
        }

        $userEmail = $employee->user?->email;

        return filled($userEmail) ? (string) $userEmail : null;
    }
}
