<?php

namespace App\Support\Users\Actions;

use App\Models\User;
use App\Support\Uploads\UploadedFileStorage;
use App\Support\Users\LastCompanyOwnerGuard;
use App\Support\Users\UserMembershipAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role as SpatieRole;
use Throwable;

final class UpdateOrganizationUser
{
    /**
     * Apply a home-company user update atomically.
     *
     * LastCompanyOwnerGuard runs inside the transaction with lockForUpdate().
     * Staged avatar files are discarded when the mutation is rejected; the
     * previous avatar is deleted only after a successful commit.
     *
     * @param  array{name: string, email: string, status: string}  $attributes
     */
    public function handle(
        User $user,
        int $companyId,
        array $attributes,
        ?int $roleId,
        ?int $employeeId,
        ?UploadedFile $avatar = null,
        bool $useEmployeeAvatar = false,
    ): void {
        $previousAvatar = $user->avatar;
        $stagedAvatar = null;

        try {
            if ($avatar !== null) {
                $stagedAvatar = UploadedFileStorage::store($avatar, 'user-avatars', 'public');
                $attributes['avatar'] = $stagedAvatar;
            } elseif ($useEmployeeAvatar) {
                $stagedAvatar = app(CopyEmployeeAvatarToUser::class)->stageCopy(
                    $companyId,
                    $employeeId,
                    $user->id,
                );

                if ($stagedAvatar === null) {
                    throw ValidationException::withMessages([
                        'avatar' => 'No employee photo is available for this user.',
                    ]);
                }

                $attributes['avatar'] = $stagedAvatar;
            }

            DB::transaction(function () use ($user, $companyId, $attributes, $roleId, $employeeId): void {
                $isRoleChangeToNonOwner = true;

                if ($roleId !== null) {
                    $newRole = SpatieRole::query()
                        ->whereKey($roleId)
                        ->where('company_id', $companyId)
                        ->first();
                    $isRoleChangeToNonOwner = $newRole === null || $newRole->name !== 'Owner';
                }

                if (($attributes['status'] ?? 'active') !== 'active' || $isRoleChangeToNonOwner) {
                    if (! LastCompanyOwnerGuard::check($user, $companyId)) {
                        abort(400, 'Cannot perform this action: the company must have at least one active Owner.');
                    }
                }

                app(SyncUserEmployeeLink::class)->handle($user, $companyId, $employeeId);

                $user->update($attributes);

                UserMembershipAccess::syncRole($user, $companyId, $roleId);
            });
        } catch (Throwable $exception) {
            $this->deleteStagedAvatar($stagedAvatar, $previousAvatar);

            throw $exception;
        }

        if ($stagedAvatar !== null && $previousAvatar && $previousAvatar !== $stagedAvatar) {
            Storage::disk('public')->delete($previousAvatar);
        }
    }

    private function deleteStagedAvatar(?string $stagedAvatar, ?string $previousAvatar): void
    {
        if ($stagedAvatar === null || $stagedAvatar === $previousAvatar) {
            return;
        }

        Storage::disk('public')->delete($stagedAvatar);
    }
}
