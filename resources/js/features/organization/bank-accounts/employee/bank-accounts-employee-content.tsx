import { Link } from '@inertiajs/react';
import { User } from 'lucide-react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { Button } from '@/components/ui/button';
import { BankAccountsBreadcrumbs } from '@/features/organization/bank-accounts/employee/bank-accounts-breadcrumbs';
import type { BankAccountEmployeeBrowseProps } from '@/features/organization/bank-accounts/types';
import { buildEmployeeShowUrl } from '@/features/organization/employees/build-employee-show-url';
import { EmployeeProfileLink } from '@/features/organization/employees/components/employee-profile-link';
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
                title={
                    <EmployeeProfileLink
                        employeeId={employee.id}
                        className="hover:underline"
                    >
                        {employee.name}
                    </EmployeeProfileLink>
                }
                description={employee.employee_no}
                backHref={back.href}
                backLabel={back.label}
                actions={
                    <Button
                        variant="outline"
                        className="h-12 rounded-xl border-input bg-background/50 px-6 hover:bg-muted dark:border-white/5 dark:bg-white/5 dark:hover:bg-white/10"
                        asChild
                    >
                        <Link href={buildEmployeeShowUrl(employee.id)}>
                            <User className="mr-2 size-4" />
                            View profile
                        </Link>
                    </Button>
                }
            />

            <EmployeeBankTab
                employeeId={employee.id}
                bank_accounts={bank_accounts}
                banks={banks}
                canManage={can.create && can.update && can.delete}
                templateFields={template_bank_account_fields}
                standalone
            />
        </Main>
    );
}

