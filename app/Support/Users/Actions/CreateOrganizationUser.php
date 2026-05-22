<?php

namespace App\Support\Users\Actions;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

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
            $data['avatar'] = $avatar->store('user-avatars', 'public');
        } elseif (array_key_exists('avatar', $attributes)) {
            $data['avatar'] = $attributes['avatar'];
        }

        $user = User::create($data);

        $user->companies()->syncWithoutDetaching([
            $companyId => ['status' => 'active'],
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($companyId);

        if ($roleId !== null) {
            $role = SpatieRole::query()->whereKey($roleId)->firstOrFail();
            abort_unless((int) $role->company_id === $companyId, 422);
            $user->syncRoles([$role->name]);
        } else {
            $user->syncRoles([]);
        }

        return $user;
    }
}
