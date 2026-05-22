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
        $employee = Employee::query()
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->first();

        if ($employee === null || blank($employee->image)) {
            return false;
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($employee->image)) {
            return false;
        }

        if ($user->avatar) {
            $disk->delete($user->avatar);
        }

        $extension = pathinfo($employee->image, PATHINFO_EXTENSION) ?: 'jpg';
        $destination = 'user-avatars/'.Str::uuid().'.'.$extension;

        $disk->copy($employee->image, $destination);

        $user->update(['avatar' => $destination]);

        return true;
    }
}
