<?php

namespace App\Support\Documents\RecipientRequests;

use App\Models\User;
use App\Support\Companies\ResolveCompanyAccess;
use Illuminate\Support\Facades\DB;

final class DocumentRecipientSignatoryOptionsQuery
{
    public function __construct(
        private ResolveCompanyAccess $companyAccess,
    ) {}

    /**
     * @return list<array{id: int, name: string, email: string|null}>
     */
    public function forCompany(int $companyId): array
    {
        $users = User::query()
            ->select('users.id', 'users.name', 'users.email', 'users.status', 'users.company_id')
            ->where('users.status', 'active')
            ->where(function ($query) use ($companyId): void {
                $query->where('users.company_id', $companyId)
                    ->orWhereExists(function ($inner) use ($companyId): void {
                        $inner->select(DB::raw(1))
                            ->from('company_user')
                            ->whereColumn('company_user.user_id', 'users.id')
                            ->where('company_user.company_id', $companyId)
                            ->where('company_user.status', 'active');
                    });
            })
            ->orderBy('users.name')
            ->get();

        return $users
            ->filter(fn (User $user): bool => $this->companyAccess->hasAccessibleMembership($user, $companyId)
                && $user->can('documents.recipient-requests.respond'))
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => (string) $user->name,
                'email' => $user->email,
            ])
            ->values()
            ->all();
    }
}
