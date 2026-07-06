import { Head } from '@inertiajs/react';
import { BankAccountsEmployeeContent } from '@/features/organization/bank-accounts/employee/bank-accounts-employee-content';
import type { BankAccountEmployeeBrowseProps } from '@/features/organization/bank-accounts/types';

export default function BankAccountEmployeeBrowse(
    props: BankAccountEmployeeBrowseProps,
) {
    return (
        <>
            <Head title={`Bank Accounts — ${props.employee.name}`} />
            <BankAccountsEmployeeContent {...props} />
        </>
    );
}
