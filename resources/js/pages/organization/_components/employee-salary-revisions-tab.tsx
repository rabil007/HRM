import type { ReactElement } from 'react';
import { EmptyState } from '@/components/design-system/empty-state';
import {
    formatContractStatus,
    formatPayrollCategory,
    formatSalaryStructure,
} from '@/features/organization/contracts/contracts-format';
import { EmployeeContractSalaryRevisions } from '@/features/organization/contracts/employee-contract-salary-revisions';
import { surfaces, typography } from '@/lib/design-system';
import { formatIsoDateDisplay } from '@/pages/organization/_lib/format-iso-date-display';
import type { EmployeeContractDetails } from '@/pages/organization/employee-page.types';

export type EmployeeSalaryRevisionsTabProps = {
    employeeId: number | null;
    contracts: EmployeeContractDetails[];
    canCreate: boolean;
    canUpdate: boolean;
    canDelete: boolean;
};

function contractIsCrewDaily(contract: EmployeeContractDetails): boolean {
    return (
        contract.payroll_category === 'crew' &&
        (contract.salary_structure ?? 'daily') !== 'monthly'
    );
}

function contractIsOfficeOrCrewMonthly(
    contract: EmployeeContractDetails,
): boolean {
    return (
        contract.payroll_category !== 'crew' ||
        contract.salary_structure === 'monthly'
    );
}

export function EmployeeSalaryRevisionsTab({
    employeeId,
    contracts,
    canCreate,
    canUpdate,
    canDelete,
}: EmployeeSalaryRevisionsTabProps): ReactElement {
    if (employeeId === null) {
        return (
            <EmptyState
                title="No employee yet"
                description="Save the employee profile before managing salary revisions."
            />
        );
    }

    if (contracts.length === 0) {
        return (
            <EmptyState
                title="No contracts"
                description="Add a contract first, then salary revisions will appear here."
            />
        );
    }

    return (
        <div className="space-y-4">
            {contracts.map((contract) => {
                const revisionCount = contract.salary_revisions?.length ?? 0;
                const period = [
                    formatIsoDateDisplay(contract.start_date),
                    formatIsoDateDisplay(contract.end_date),
                ].join(' – ');

                return (
                    <div key={contract.id} className={surfaces.panel}>
                        <div className={surfaces.panelHeader}>
                            <div className="min-w-0 space-y-1">
                                <h3 className={surfaces.panelTitle}>
                                    Contract #{contract.id}
                                </h3>
                                <p className={typography.muted}>
                                    {period}
                                    {' · '}
                                    {formatContractStatus(contract.status)}
                                    {' · '}
                                    {formatPayrollCategory(
                                        contract.payroll_category,
                                    )}
                                    {' · '}
                                    {formatSalaryStructure(
                                        contract.salary_structure ??
                                            (contract.payroll_category ===
                                            'crew'
                                                ? 'daily'
                                                : 'monthly'),
                                    )}
                                </p>
                            </div>
                            <span className={surfaces.panelBadge}>
                                {revisionCount}{' '}
                                {revisionCount === 1 ? 'revision' : 'revisions'}
                            </span>
                        </div>
                        <div className="p-4 pt-0">
                            <EmployeeContractSalaryRevisions
                                employeeId={employeeId}
                                contract={contract}
                                canCreate={canCreate}
                                canUpdate={canUpdate}
                                canDelete={canDelete}
                                isCrewDaily={contractIsCrewDaily(contract)}
                                isOfficeOrCrewMonthly={contractIsOfficeOrCrewMonthly(
                                    contract,
                                )}
                                hideHeader
                            />
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
