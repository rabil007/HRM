import { Link, router } from '@inertiajs/react';
import {
    Banknote,
    CalendarDays,
    FileText,
    Pencil,
    Trash2,
    User,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactElement } from 'react';
import { destroy as destroyContract } from '@/actions/App/Http/Controllers/Organization/EmployeeContractController';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { RecentActivityCard } from '@/components/recent-activity-card';
import type { RecentActivityItem } from '@/components/recent-activity-card';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { buildContractEmployeeUrl } from '@/features/organization/contracts/build-contract-employee-url';
import {
    contractCrewSalaryTotal,
    contractOfficeSalaryTotal,
    formatContractMoney,
    formatSalaryStructure,
} from '@/features/organization/contracts/contracts-format';
import { ContractsBreadcrumbs } from '@/features/organization/contracts/employee/contracts-breadcrumbs';
import { EmployeeContractSalaryRevisions } from '@/features/organization/contracts/employee-contract-salary-revisions';
import type {
    ContractListItem,
    ContractPageCan,
    ContractBackNavigation,
} from '@/features/organization/contracts/types';
import { buildEmployeeShowUrl } from '@/features/organization/employees/build-employee-show-url';
import { EmployeeProfileLink } from '@/features/organization/employees/components/employee-profile-link';
import { actions } from '@/lib/design-system';
import { formatDisplayDate } from '@/lib/format-date';

type Props = {
    contract: ContractListItem;
    can: ContractPageCan;
    back: ContractBackNavigation;
    recent_activity: RecentActivityItem[];
    can_view_audit: boolean;
};

function displayValue(value: string | null | undefined): string {
    return value && value.trim() !== '' ? value : '—';
}

function DetailField({
    label,
    value,
}: {
    label: string;
    value: string;
}): ReactElement {
    return (
        <div className="space-y-1">
            <div className="text-[10px] font-bold tracking-[0.16em] text-muted-foreground/70 uppercase">
                {label}
            </div>
            <div className="text-sm font-medium text-foreground">{value}</div>
        </div>
    );
}

export function ContractsShowContent({
    contract,
    can,
    back,
    recent_activity,
    can_view_audit,
}: Props): ReactElement {
    const [deleteOpen, setDeleteOpen] = useState(false);
    const isCrew = contract.payroll_category === 'crew';
    const isCrewDaily =
        isCrew && (contract.salary_structure ?? 'daily') !== 'monthly';
    const isOfficeOrCrewMonthly = !isCrew || !isCrewDaily;
    const total = isCrewDaily
        ? contractCrewSalaryTotal(contract)
        : contractOfficeSalaryTotal(contract);

    const editHref = buildContractEmployeeUrl(
        contract.employee_id,
        { from: 'index' },
        { editContractId: contract.id },
    );

    const headerButtonClass =
        'h-12 rounded-xl border-input bg-background/50 px-6 hover:bg-muted dark:border-white/5 dark:bg-white/5 dark:hover:bg-white/10';

    return (
        <Main>
            <ContractsBreadcrumbs
                items={[
                    { title: 'Contracts', href: back.href },
                    { title: contract.employee_name },
                ]}
            />

            <DetailsHeader
                title={
                    <EmployeeProfileLink
                        employeeId={contract.employee_id}
                        className="hover:underline"
                    >
                        {contract.employee_name}
                    </EmployeeProfileLink>
                }
                description={`${contract.employee_no} · ${
                    contract.payroll_category === 'crew' ? 'Crew' : 'Office'
                } · ${formatSalaryStructure(contract.salary_structure)}`}
                backHref={back.href}
                backLabel={back.label}
                actions={
                    <>
                        {can.update ? (
                            <Button
                                variant="outline"
                                className={headerButtonClass}
                                asChild
                            >
                                <Link href={editHref}>
                                    <Pencil className="mr-2 size-4" />
                                    Edit
                                </Link>
                            </Button>
                        ) : null}
                        {can.delete ? (
                            <Button
                                variant="outline"
                                className={headerButtonClass}
                                onClick={() => setDeleteOpen(true)}
                            >
                                <Trash2 className="mr-2 size-4" />
                                Delete
                            </Button>
                        ) : null}
                        <Button
                            variant="outline"
                            className={headerButtonClass}
                            asChild
                        >
                            <Link
                                href={buildEmployeeShowUrl(
                                    contract.employee_id,
                                )}
                            >
                                <User className="mr-2 size-4" />
                                View profile
                            </Link>
                        </Button>
                        <Button
                            variant="outline"
                            className={headerButtonClass}
                            asChild
                        >
                            <Link
                                href={buildContractEmployeeUrl(
                                    contract.employee_id,
                                    { from: 'index' },
                                )}
                            >
                                <FileText className="mr-2 size-4" />
                                All contracts
                            </Link>
                        </Button>
                    </>
                }
            />

            <div className="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
                <div className="space-y-6">
                    <Card className="border-border/60 bg-card/40 shadow-none">
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <FileText className="size-4 text-muted-foreground" />
                                Contract details
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2">
                            <DetailField
                                label="Status"
                                value={displayValue(contract.status)}
                            />
                            <DetailField
                                label="Labor contract ID"
                                value={displayValue(contract.labor_contract_id)}
                            />
                            <DetailField
                                label="Start date"
                                value={formatDisplayDate(contract.start_date)}
                            />
                            <DetailField
                                label="End date"
                                value={formatDisplayDate(contract.end_date)}
                            />
                            <DetailField
                                label="Department"
                                value={displayValue(contract.department_name)}
                            />
                            <DetailField
                                label="Position"
                                value={displayValue(contract.position_title)}
                            />
                        </CardContent>
                    </Card>

                    <Card className="border-border/60 bg-card/40 shadow-none">
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Banknote className="size-4 text-muted-foreground" />
                                Current package
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2">
                            <DetailField
                                label="Basic"
                                value={formatContractMoney(
                                    contract.basic_salary,
                                )}
                            />
                            {isOfficeOrCrewMonthly ? (
                                <>
                                    <DetailField
                                        label="Housing"
                                        value={formatContractMoney(
                                            contract.housing_allowance,
                                        )}
                                    />
                                    <DetailField
                                        label="Transport"
                                        value={formatContractMoney(
                                            contract.transport_allowance,
                                        )}
                                    />
                                    <DetailField
                                        label="Other"
                                        value={formatContractMoney(
                                            contract.other_allowances,
                                        )}
                                    />
                                </>
                            ) : null}
                            {isCrewDaily ? (
                                <>
                                    <DetailField
                                        label="Supplementary"
                                        value={formatContractMoney(
                                            contract.supplementary_allowance,
                                        )}
                                    />
                                    <DetailField
                                        label="Site allowance"
                                        value={formatContractMoney(
                                            contract.site_allowance,
                                        )}
                                    />
                                </>
                            ) : null}
                            <div className="sm:col-span-2">
                                <DetailField
                                    label="Total"
                                    value={formatContractMoney(total)}
                                />
                            </div>
                            {contract.note ? (
                                <div className="sm:col-span-2">
                                    <DetailField
                                        label="Note"
                                        value={contract.note}
                                    />
                                </div>
                            ) : null}
                        </CardContent>
                    </Card>

                    <Card className="border-border/60 bg-card/40 shadow-none">
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <CalendarDays className="size-4 text-muted-foreground" />
                                Salary revisions
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <EmployeeContractSalaryRevisions
                                employeeId={contract.employee_id}
                                contract={contract}
                                canCreate={can.salary_revisions_create}
                                canUpdate={can.salary_revisions_update}
                                canDelete={can.salary_revisions_delete}
                                isCrewDaily={isCrewDaily}
                                isOfficeOrCrewMonthly={isOfficeOrCrewMonthly}
                                hideHeader
                                reloadOnly={['contract']}
                            />
                        </CardContent>
                    </Card>
                </div>

                <div className="space-y-6">
                    {can_view_audit ? (
                        <RecentActivityCard
                            items={recent_activity}
                            description="Latest changes for this contract."
                        />
                    ) : null}
                </div>
            </div>

            <ConfirmDeleteDialog
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
                title="Delete contract?"
                description="This permanently removes the contract and its salary revision history. Linked payroll records may block deletion."
                confirmText="Delete"
                onConfirm={() => {
                    router.delete(
                        destroyContract.url({
                            employee: contract.employee_id,
                            employeeContract: contract.id,
                        }),
                        {
                            onSuccess: () => {
                                setDeleteOpen(false);
                                router.visit(back.href);
                            },
                            onError: () => setDeleteOpen(false),
                        },
                    );
                }}
                confirmButtonClassName={`${actions.dialogPrimary} bg-destructive text-destructive-foreground hover:bg-destructive/90`}
            />
        </Main>
    );
}
