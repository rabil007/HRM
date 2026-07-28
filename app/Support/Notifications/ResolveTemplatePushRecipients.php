<?php

namespace App\Support\Notifications;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\PermissionRegistrar;

/**
 * Resolve OMS-HRM users who may receive browser push for template email presets.
 *
 * Matching is company-scoped: users.email, employee work_email, and employee
 * personal_email. Callers must pass the company being processed by the server.
 */
final class ResolveTemplatePushRecipients
{
    /**
     * @param  list<string>  $emails
     * @return Collection<int, User>
     */
    public function handle(Company $company, array $emails): Collection
    {
        if ($company->status !== 'active') {
            return collect();
        }

        $normalized = collect($emails)
            ->map(fn (string $email): string => strtolower(trim($email)))
            ->filter(fn (string $email): bool => $email !== '')
            ->unique()
            ->values();

        if ($normalized->isEmpty()) {
            return collect();
        }

        $userIds = collect();

        $directUserIds = User::query()
            ->whereNull('deleted_at')
            ->where(function ($query) use ($normalized): void {
                foreach ($normalized as $email) {
                    $query->orWhereRaw('LOWER(TRIM(email)) = ?', [$email]);
                }
            })
            ->pluck('id');

        $userIds = $userIds->merge($directUserIds);

        $employeeLinkedUserIds = Employee::query()
            ->where('company_id', $company->id)
            ->whereNotNull('user_id')
            ->where(function ($query) use ($normalized): void {
                foreach ($normalized as $email) {
                    $query->orWhereRaw('LOWER(TRIM(work_email)) = ?', [$email])
                        ->orWhereRaw('LOWER(TRIM(personal_email)) = ?', [$email]);
                }
            })
            ->pluck('user_id');

        $userIds = $userIds->merge($employeeLinkedUserIds)->unique()->filter()->values();

        if ($userIds->isEmpty()) {
            return collect();
        }

        $users = User::query()
            ->whereIn('id', $userIds)
            ->where(function ($query): void {
                $query->whereNull('status')
                    ->orWhere('status', 'active');
            })
            ->whereNull('deleted_at')
            ->get()
            ->filter(fn (User $user): bool => $this->hasActiveMembership($user, $company))
            ->filter(fn (User $user): bool => $this->canViewDocuments($user, $company))
            ->values();

        return $users->keyBy('id');
    }

    private function hasActiveMembership(User $user, Company $company): bool
    {
        return $user->companies()
            ->whereKey($company->id)
            ->wherePivot('status', 'active')
            ->exists();
    }

    private function canViewDocuments(User $user, Company $company): bool
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId($company->id);

            return $user->can('documents.view');
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }
    }
}
