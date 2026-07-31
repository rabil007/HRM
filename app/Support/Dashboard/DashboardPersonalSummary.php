<?php

namespace App\Support\Dashboard;

use App\Models\Company;
use App\Models\User;

final class DashboardPersonalSummary
{
    /**
     * @return array{user_name: string, company_name: string, today: string}
     */
    public static function for(?User $user, int $companyId): array
    {
        $companyName = (string) (Company::query()
            ->whereKey($companyId)
            ->value('name') ?? '');

        return [
            'user_name' => $user?->name ?? '',
            'company_name' => $companyName,
            'today' => now()->toDateString(),
        ];
    }
}
