<?php

namespace App\Support\Users\Actions;

use App\Models\User;
use App\Support\Uploads\UploadedFileStorage;
use App\Support\Users\UserMembershipAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;

final class CreateOrganizationUser
{
    /**
     * @param  array{status?: string, avatar?: string|null}  $attributes
     */
    public function handle(
        int $companyId,
        string $name,
        string $email,
        string $password,
        ?int $roleId = null,
        array $attributes = [],
        ?UploadedFile $avatar = null,
    ): User {
        $data = [
            'company_id' => $companyId,
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'status' => $attributes['status'] ?? 'active',
        ];

        if ($avatar !== null) {
            $data['avatar'] = UploadedFileStorage::store($avatar, 'user-avatars', 'public');
        } elseif (array_key_exists('avatar', $attributes)) {
            $data['avatar'] = $attributes['avatar'];
        }

        $user = User::create($data);

        $user->companies()->syncWithoutDetaching([
            $companyId => ['status' => 'active'],
        ]);

        UserMembershipAccess::syncRole($user, $companyId, $roleId);

        return $user;
    }
}
