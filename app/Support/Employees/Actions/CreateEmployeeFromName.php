<?php

namespace App\Support\Employees\Actions;

use App\Models\Employee;
use Illuminate\Support\Str;

final class CreateEmployeeFromName
{
    public function handle(
        string $name,
        int $companyId,
        ?int $employeeProfileTemplateId = null,
        ?int $createdByUserId = null,
        ?string $ensureKey = null,
    ): Employee {
        $trimmedName = trim($name);
        $normalizedKey = self::normalizeEnsureKey($ensureKey);

        return Employee::query()->create([
            'company_id' => $companyId,
            'employee_profile_template_id' => $employeeProfileTemplateId,
            'employee_no' => $this->generateDraftEmployeeNumber($companyId),
            'name' => $trimmedName,
            'status' => 'active',
            'provisional_created_by' => $createdByUserId,
            'provisional_ensure_key' => $normalizedKey,
        ]);
    }

    public static function normalizeEnsureKey(?string $ensureKey): ?string
    {
        if ($ensureKey === null) {
            return null;
        }

        $trimmed = trim($ensureKey);

        if ($trimmed === '' || strlen($trimmed) < 16 || strlen($trimmed) > 64) {
            return null;
        }

        if (! preg_match('/^[A-Za-z0-9_-]+$/', $trimmed)) {
            return null;
        }

        return $trimmed;
    }

    private function generateDraftEmployeeNumber(int $companyId): string
    {
        do {
            $candidate = 'DRAFT-'.Str::upper(Str::random(8));
        } while (
            Employee::query()
                ->where('company_id', $companyId)
                ->where('employee_no', $candidate)
                ->exists()
        );

        return $candidate;
    }
}
