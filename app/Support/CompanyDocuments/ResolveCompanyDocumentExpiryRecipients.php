<?php

namespace App\Support\CompanyDocuments;

use App\Models\CompanyDocumentExpiryNotificationRecipient;
use App\Models\CompanyDocumentExpiryNotificationSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ResolveCompanyDocumentExpiryRecipients
{
    /**
     * Active company members who can actually receive email.
     *
     * @return Collection<int, User>
     */
    public function eligibleUsersForCompany(int $companyId): Collection
    {
        return $this->eligibleMembersQuery($companyId)
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->filter(fn (User $user): bool => $this->hasUsableEmail($user))
            ->values();
    }

    /**
     * @param  list<int|string>  $userIds
     * @return list<int>
     */
    public function eligibleUserIdsForCompany(int $companyId, array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        if ($userIds === []) {
            return [];
        }

        return $this->eligibleMembersQuery($companyId)
            ->whereIn('users.id', $userIds)
            ->get(['id', 'name', 'email'])
            ->filter(fn (User $user): bool => $this->hasUsableEmail($user))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Re-check stored recipients against current active membership and usable email.
     *
     * @return array{
     *     to_addresses: list<string>,
     *     cc_addresses: list<string>,
     *     to_users: Collection<int, User>,
     *     cc_users: Collection<int, User>
     * }
     */
    public function handle(CompanyDocumentExpiryNotificationSetting $setting, int $companyId): array
    {
        $eligibleById = $this->eligibleUsersForCompany($companyId)->keyBy('id');

        $toUsers = $this->resolveChannel($setting->toRecipients, $eligibleById);
        $seenEmails = $toUsers
            ->map(fn (User $user): string => strtolower(trim($user->email)))
            ->all();

        $ccUsers = $this->resolveChannel($setting->ccRecipients, $eligibleById)
            ->reject(fn (User $user): bool => in_array(strtolower(trim($user->email)), $seenEmails, true))
            ->values();

        return [
            'to_addresses' => $toUsers->map(fn (User $user): string => $user->email)->values()->all(),
            'cc_addresses' => $ccUsers->map(fn (User $user): string => $user->email)->values()->all(),
            'to_users' => $toUsers,
            'cc_users' => $ccUsers,
        ];
    }

    /**
     * @return Builder<User>
     */
    private function eligibleMembersQuery(int $companyId): Builder
    {
        return User::query()
            ->where(function ($query): void {
                $query->whereNull('status')
                    ->orWhere('status', 'active');
            })
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->whereExists(function ($query) use ($companyId): void {
                $query->selectRaw('1')
                    ->from('company_user')
                    ->whereColumn('company_user.user_id', 'users.id')
                    ->where('company_user.company_id', $companyId)
                    ->where('company_user.status', 'active');
            });
    }

    /**
     * @param  Collection<int, CompanyDocumentExpiryNotificationRecipient>  $recipients
     * @param  Collection<int, User>  $eligibleById
     * @return Collection<int, User>
     */
    private function resolveChannel(Collection $recipients, Collection $eligibleById): Collection
    {
        $resolved = collect();
        $seenEmails = [];

        foreach ($recipients as $recipient) {
            $user = $eligibleById->get((int) $recipient->user_id);

            if (! $user instanceof User) {
                continue;
            }

            $normalized = strtolower(trim($user->email));

            if ($normalized === '' || in_array($normalized, $seenEmails, true)) {
                continue;
            }

            $seenEmails[] = $normalized;
            $resolved->push($user);
        }

        return $resolved->values();
    }

    private function hasUsableEmail(User $user): bool
    {
        $email = trim((string) $user->email);

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
