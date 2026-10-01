<?php

namespace App\Support\EmployeeDocuments\DocumentExpiryNotification;

use App\Enums\DocumentExpiryNotificationRecipientKind;
use App\Models\DocumentExpiryNotificationRule;
use App\Models\DocumentExpiryNotificationRuleRecipient;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ResolveDocumentExpiryNotificationRecipients
{
    /**
     * Active company members who can be selected as routing recipients.
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
     * Resolve live TO/CC addresses and users for delivery.
     *
     * @return array{
     *     to_addresses: list<string>,
     *     cc_addresses: list<string>,
     *     to_users: Collection<int, User>,
     *     cc_users: Collection<int, User>,
     *     to_manual_emails: list<string>,
     *     cc_manual_emails: list<string>
     * }
     */
    public function handle(DocumentExpiryNotificationRule $rule, int $companyId): array
    {
        $rule->loadMissing([
            'toRecipients.user:id,name,email,status',
            'ccRecipients.user:id,name,email,status',
        ]);

        $eligibleById = $this->eligibleUsersForCompany($companyId)->keyBy('id');

        $toUsers = collect();
        $toManualEmails = [];
        $seenEmails = [];

        foreach ($rule->toRecipients as $recipient) {
            $resolved = $this->resolveRecipientAddress($recipient, $eligibleById);

            if ($resolved === null) {
                continue;
            }

            $normalized = strtolower($resolved['email']);

            if (in_array($normalized, $seenEmails, true)) {
                continue;
            }

            $seenEmails[] = $normalized;

            if ($resolved['user'] instanceof User) {
                $toUsers->push($resolved['user']);
            } else {
                $toManualEmails[] = $resolved['email'];
            }
        }

        $ccUsers = collect();
        $ccManualEmails = [];

        foreach ($rule->ccRecipients as $recipient) {
            $resolved = $this->resolveRecipientAddress($recipient, $eligibleById);

            if ($resolved === null) {
                continue;
            }

            $normalized = strtolower($resolved['email']);

            if (in_array($normalized, $seenEmails, true)) {
                continue;
            }

            $seenEmails[] = $normalized;

            if ($resolved['user'] instanceof User) {
                $ccUsers->push($resolved['user']);
            } else {
                $ccManualEmails[] = $resolved['email'];
            }
        }

        return [
            'to_addresses' => [
                ...$toUsers->map(fn (User $user): string => $user->email)->values()->all(),
                ...$toManualEmails,
            ],
            'cc_addresses' => [
                ...$ccUsers->map(fn (User $user): string => $user->email)->values()->all(),
                ...$ccManualEmails,
            ],
            'to_users' => $toUsers->values(),
            'cc_users' => $ccUsers->values(),
            'to_manual_emails' => $toManualEmails,
            'cc_manual_emails' => $ccManualEmails,
        ];
    }

    /**
     * Whether an internal user may see a given employee for expiry alert content.
     */
    public function userCanSeeEmployee(User $user, int $companyId, int $employeeId): bool
    {
        return EmployeeVisibilityScope::canAccessId($user, $employeeId, $companyId);
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
     * @param  Collection<int, User>  $eligibleById
     * @return array{email: string, user: User|null}|null
     */
    private function resolveRecipientAddress(
        DocumentExpiryNotificationRuleRecipient $recipient,
        Collection $eligibleById,
    ): ?array {
        if ($recipient->recipient_kind === DocumentExpiryNotificationRecipientKind::User) {
            $user = $eligibleById->get((int) $recipient->user_id);

            if (! $user instanceof User || ! $this->hasUsableEmail($user)) {
                return null;
            }

            return [
                'email' => trim($user->email),
                'user' => $user,
            ];
        }

        if ($recipient->recipient_kind === DocumentExpiryNotificationRecipientKind::Email) {
            $email = strtolower(trim((string) $recipient->email));

            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return null;
            }

            return [
                'email' => $email,
                'user' => null,
            ];
        }

        return null;
    }

    private function hasUsableEmail(User $user): bool
    {
        $email = trim((string) $user->email);

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
