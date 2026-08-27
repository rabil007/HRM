<?php

namespace App\Support\Users\Actions;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CopyEmployeeAvatarToUser
{
    public function handle(User $user, int $companyId): bool
    {
        $staged = $this->stageCopy($companyId, null, $user->id);

        if ($staged === null) {
            return false;
        }

        $previous = $user->avatar;
        $user->update(['avatar' => $staged]);

        if ($previous && $previous !== $staged) {
            Storage::disk('public')->delete($previous);
        }

        return true;
    }

    /**
     * Copy the employee photo to a new user-avatars path without mutating the user
     * or deleting the current avatar. Caller is responsible for cleanup on failure.
     */
    public function stageCopy(int $companyId, ?int $employeeId = null, ?int $userId = null): ?string
    {
        $query = Employee::query()->where('company_id', $companyId);

        if ($employeeId !== null) {
            $query->whereKey($employeeId);
        } elseif ($userId !== null) {
            $query->where('user_id', $userId);
        } else {
            return null;
        }

        $employee = $query->first();

        if ($employee === null || blank($employee->image)) {
            return null;
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($employee->image)) {
            return null;
        }

        $extension = pathinfo($employee->image, PATHINFO_EXTENSION) ?: 'jpg';
        $destination = 'user-avatars/'.Str::uuid().'.'.$extension;

        $disk->copy($employee->image, $destination);

        return $destination;
    }
}
