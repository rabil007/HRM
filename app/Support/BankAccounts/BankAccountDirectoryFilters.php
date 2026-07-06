<?php

namespace App\Support\BankAccounts;

use Illuminate\Http\Request;

final class BankAccountDirectoryFilters
{
    public function __construct(
        public readonly string $search = '',
        public readonly string $bankId = '',
        public readonly string $isPrimary = '',
        public readonly string $branchId = '',
        public readonly string $departmentId = '',
        public readonly string $paymentMethod = '',
    ) {}

    public static function fromRequest(Request $request): self
    {
        $isPrimary = (string) $request->query('is_primary', '');

        if (in_array($isPrimary, ['1', 'true', 'primary'], true)) {
            $isPrimary = 'primary';
        } elseif (in_array($isPrimary, ['0', 'false', 'secondary'], true)) {
            $isPrimary = 'secondary';
        } else {
            $isPrimary = '';
        }

        return new self(
            search: trim((string) $request->query('search', '')),
            bankId: (string) $request->query('bank_id', ''),
            isPrimary: $isPrimary,
            branchId: (string) $request->query('branch_id', ''),
            departmentId: (string) $request->query('department_id', ''),
            paymentMethod: (string) $request->query('payment_method', ''),
        );
    }

    /**
     * @return array<string, string>
     */
    public function toQueryArray(): array
    {
        $query = [];

        if ($this->search !== '') {
            $query['search'] = $this->search;
        }

        if ($this->bankId !== '') {
            $query['bank_id'] = $this->bankId;
        }

        if ($this->isPrimary !== '') {
            $query['is_primary'] = $this->isPrimary;
        }

        if ($this->branchId !== '') {
            $query['branch_id'] = $this->branchId;
        }

        if ($this->departmentId !== '') {
            $query['department_id'] = $this->departmentId;
        }

        if ($this->paymentMethod !== '') {
            $query['payment_method'] = $this->paymentMethod;
        }

        return $query;
    }
}
