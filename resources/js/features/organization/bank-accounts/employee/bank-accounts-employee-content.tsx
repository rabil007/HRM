import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { Main } from '@/components/layout/main';
import { DetailsHeader } from '@/components/details-header';
import { BankAccountsBreadcrumbs } from '@/features/organization/bank-accounts/employee/bank-accounts-breadcrumbs';
import type { BankAccountEmployeeBrowseProps } from '@/features/organization/bank-accounts/types';
import { EmployeeBankTab } from '@/pages/organization/_components/employee-bank-tab';

export function BankAccountsEmployeeContent({
    employee,
    bank_accounts,
    banks,
    template_bank_account_fields,
    back,
    can,
}: BankAccountEmployeeBrowseProps) {
    return (
        <Main>
            <BankAccountsBreadcrumbs
                items={[
                    { title: 'Bank Accounts', href: back.href },
                    { title: employee.name },
                ]}
            />

            <DetailsHeader
                title={employee.name}
                subtitle={employee.employee_no}
                backHref={back.href}
                backLabel={back.label}
                actions={
                    <Link
                        href={back.href}
                        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" aria-hidden />
                        Back to bank accounts
                    </Link>
                }
            />

            <EmployeeBankTab
                employeeId={employee.id}
                bank_accounts={bank_accounts}
                banks={banks}
                canManage={can.manage || (can.create && can.update && can.delete)}
                templateFields={template_bank_account_fields}
                standalone
            />
        </Main>
    );
}
