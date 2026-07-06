<?php

namespace App\Support\BankAccounts;

use Illuminate\Http\Request;

final class BankAccountShowBackNavigation
{
    /**
     * @return array{href: string, label: string}
     */
    public static function resolve(Request $request): array
    {
        $from = (string) $request->query('from', 'index');

        if ($from !== 'index') {
            return [
                'href' => route('organization.bank-accounts'),
                'label' => 'Bank Accounts',
            ];
        }

        $query = [];

        foreach (['search', 'bank_id', 'is_primary', 'payment_method', 'branch_id', 'department_id', 'page'] as $key) {
            $value = $request->query($key);

            if ($value !== null && $value !== '') {
                $query[$key] = (string) $value;
            }
        }

        return [
            'href' => route('organization.bank-accounts', $query),
            'label' => 'Bank Accounts',
        ];
    }
}
