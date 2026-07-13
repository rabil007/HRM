<?php

namespace App\Support\EmployeeTrainings;

use App\Models\Employee;
use App\Models\EmployeeTraining;

final class TrainingListResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(EmployeeTraining $training, ?Employee $employee = null): array
    {
        $employee ??= $training->employee;
        $expiryStatus = TrainingExpiry::resolve($training->expiry_date);

        return [
            'id' => $training->id,
            'course_id' => $training->course_id,
            'course_name' => $training->course?->name,
            'issue_date' => $training->issue_date?->toDateString(),
            'expiry_date' => $training->expiry_date?->toDateString(),
            'expiry_status' => $expiryStatus?->value,
            'expiry_remaining_days' => TrainingExpiry::remainingDays($training->expiry_date),
            'expiry_label' => TrainingExpiry::humanLabel($training->expiry_date),
            'institute_center' => $training->institute_center,
            'country_id' => $training->country_id,
            'country_name' => $training->country?->name,
            'has_certificate' => $training->certificate_path !== null && $training->certificate_path !== '',
            'certificate_url' => $training->certificate_url,
            'created_at' => $training->created_at?->toDateTimeString(),
            'employee_id' => $employee?->id ?? $training->employee_id,
            'employee_name' => $employee?->name ?? '',
            'employee_no' => $employee?->employee_no ?? '',
            'employee_image' => $employee?->image,
            'department_name' => $employee?->department?->name,
            'position_title' => $employee?->position?->title,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function toProfileArray(EmployeeTraining $training): array
    {
        return [
            'id' => $training->id,
            'course_id' => $training->course_id,
            'course_name' => $training->course?->name,
            'issue_date' => $training->issue_date?->toDateString(),
            'expiry_date' => $training->expiry_date?->toDateString(),
            'institute_center' => $training->institute_center,
            'country_id' => $training->country_id,
            'country_name' => $training->country?->name,
            'certificate_url' => $training->certificate_url,
            'created_at' => $training->created_at?->toDateTimeString(),
        ];
    }
}
