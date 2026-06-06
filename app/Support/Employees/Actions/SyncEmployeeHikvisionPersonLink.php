<?php

namespace App\Support\Employees\Actions;

use App\Models\Employee;
use App\Models\HikvisionPerson;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SyncEmployeeHikvisionPersonLink
{
    public function handle(Employee $employee, ?int $hikvisionPersonId): void
    {
        DB::transaction(function () use ($employee, $hikvisionPersonId): void {
            if ($hikvisionPersonId === null) {
                $employee->update(['hikvision_person_id' => null]);

                return;
            }

            $person = HikvisionPerson::query()->find($hikvisionPersonId);

            if ($person === null) {
                throw ValidationException::withMessages([
                    'hikvision_person_id' => 'The selected Hikvision person is invalid.',
                ]);
            }

            Employee::query()
                ->where('company_id', $employee->company_id)
                ->where('hikvision_person_id', $person->id)
                ->whereKeyNot($employee->id)
                ->update(['hikvision_person_id' => null]);

            $employee->update(['hikvision_person_id' => $person->id]);
        });
    }
}
