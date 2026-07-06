import { Head } from '@inertiajs/react';
import { BankAccountsContent } from '@/features/organization/bank-accounts/bank-accounts-content';
import type { BankAccountsIndexProps } from '@/features/organization/bank-accounts/types';

export default function BankAccountsIndex(props: BankAccountsIndexProps) {
    return (
        <>
            <Head title="Bank Accounts" />
            <BankAccountsContent {...props} />
        </>
    );
}
