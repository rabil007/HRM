import { Head } from '@inertiajs/react';
import { BankAccountsNoAccountContent } from '@/features/organization/bank-accounts/bank-accounts-no-account-content';
import type { NoBankAccountIndexProps } from '@/features/organization/bank-accounts/types';

export default function BankAccountsNoAccount(props: NoBankAccountIndexProps) {
    return (
        <>
            <Head title="No Bank Account Employees" />
            <BankAccountsNoAccountContent {...props} />
        </>
    );
}
