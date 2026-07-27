import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import type { PayrollShowFilters } from '../types';

const employeeGroupLabels: Record<
    Exclude<PayrollShowFilters['employee_group'], ''>,
    string
> = {
    with_bank_account: 'Bank Account Set',
    cash_payment: 'Non-bank payment',
    missing_bank_account: 'Missing Bank Account',
};

export function PayrollBoardFilteredEmptyState({
    activeEmployeeGroup,
    onShowAll,
}: {
    activeEmployeeGroup: PayrollShowFilters['employee_group'];
    onShowAll: () => void;
}) {
    const filterLabel =
        activeEmployeeGroup !== ''
            ? employeeGroupLabels[activeEmployeeGroup]
            : 'this filter';

    return (
        <EmptyState
            title="No employees in this group"
            description={`No employees match "${filterLabel}". Select Total Employees or try another category.`}
            action={
                <Button
                    type="button"
                    variant="outline"
                    className="rounded-xl"
                    onClick={onShowAll}
                >
                    Show all employees
                </Button>
            }
        />
    );
}
