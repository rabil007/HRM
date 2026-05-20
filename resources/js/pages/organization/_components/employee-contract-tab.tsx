import { useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import {
    destroy as destroyContract,
    store as storeContract,
    update as updateContract,
} from '@/actions/App/Http/Controllers/Organization/EmployeeContractController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { EmployeeRecordRowActions } from '@/components/employee-record-row-actions';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { TabsContent } from '@/components/ui/tabs';
import { EmployeeRecordDeleteDialog } from '@/features/organization/employees/profile/components/employee-record-delete-dialog';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import {
    EmployeeRecordsActionsHeader,
    EmployeeRecordsPanel,
    EmployeeRecordsTable,
    employeeRecordsTableHeadClass,
    employeeRecordsTableRowClass,
    employeeRecordsTableTdClass,
    employeeRecordsTableThClass,
} from '@/pages/organization/_components/employee-records-panel';
import { formatIsoDateDisplay } from '@/pages/organization/_lib/format-iso-date-display';
import type { EmployeeContractDetails } from '@/pages/organization/employee-page.types';

const CONTRACTS_RELOAD = {
    preserveScroll: true,
    only: ['contracts', 'employee'],
} as const;

const CONTRACT_TYPE_LABELS: Record<string, string> = {
    limited: 'Limited',
    unlimited: 'Unlimited',
    part_time: 'Part time',
    contract: 'Contract',
};

const STATUS_LABELS: Record<string, string> = {
    active: 'Active',
    ended: 'Ended',
    draft: 'Draft',
};

export type EmployeeContractTabProps = {
    employeeId: number;
    contracts: EmployeeContractDetails[];
    canManage: boolean;
};

function formatContractType(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    return CONTRACT_TYPE_LABELS[value] ?? value;
}

function formatStatus(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    return STATUS_LABELS[value] ?? value;
}

function formatMoney(value: number | null | undefined): string {
    if (value === null || value === undefined) {
        return '—';
    }

    return String(value);
}

function contractStatusClass(status: string | null | undefined): string {
    if (status === 'active') {
        return 'border-emerald-500/25 bg-emerald-500/10 text-emerald-400';
    }

    if (status === 'draft') {
        return 'border-amber-500/25 bg-amber-500/10 text-amber-300';
    }

    return 'border-zinc-500/25 bg-zinc-500/10 text-zinc-400';
}

export function EmployeeContractTab({
    employeeId,
    contracts,
    canManage,
}: EmployeeContractTabProps): ReactElement {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editingContract, setEditingContract] =
        useState<EmployeeContractDetails | null>(null);
    const [deleteContractId, setDeleteContractId] = useState<number | null>(
        null,
    );

    const contractForm = useForm({
        contract_type: 'unlimited',
        start_date: '',
        end_date: '',
        labor_contract_id: '',
        status: 'active',
        basic_salary: '',
        housing_allowance: '',
        transport_allowance: '',
        other_allowances: '',
    });

    const openCreateDialog = () => {
        contractForm.reset();
        contractForm.clearErrors();
        contractForm.setData({
            contract_type: 'unlimited',
            start_date: '',
            end_date: '',
            labor_contract_id: '',
            status: 'active',
            basic_salary: '',
            housing_allowance: '',
            transport_allowance: '',
            other_allowances: '',
        });
        setEditingContract(null);
        setDialogOpen(true);
    };

    const openEditDialog = (row: EmployeeContractDetails) => {
        contractForm.clearErrors();
        contractForm.setData({
            contract_type: row.contract_type ?? 'unlimited',
            start_date: row.start_date ?? '',
            end_date: row.end_date ?? '',
            labor_contract_id: row.labor_contract_id ?? '',
            status: row.status ?? 'active',
            basic_salary:
                row.basic_salary === null || row.basic_salary === undefined
                    ? ''
                    : String(row.basic_salary),
            housing_allowance:
                row.housing_allowance === null ||
                row.housing_allowance === undefined
                    ? ''
                    : String(row.housing_allowance),
            transport_allowance:
                row.transport_allowance === null ||
                row.transport_allowance === undefined
                    ? ''
                    : String(row.transport_allowance),
            other_allowances:
                row.other_allowances === null ||
                row.other_allowances === undefined
                    ? ''
                    : String(row.other_allowances),
        });
        setEditingContract(row);
        setDialogOpen(true);
    };

    const submitContract = () => {
        contractForm.clearErrors();
        contractForm.transform((data) => ({
            contract_type: data.contract_type,
            start_date: data.start_date,
            end_date: data.end_date === '' ? null : data.end_date,
            labor_contract_id:
                data.labor_contract_id.trim() === ''
                    ? null
                    : data.labor_contract_id.trim(),
            status: data.status,
            basic_salary: data.basic_salary === '' ? null : data.basic_salary,
            housing_allowance:
                data.housing_allowance === '' ? null : data.housing_allowance,
            transport_allowance:
                data.transport_allowance === '' ? null : data.transport_allowance,
            other_allowances:
                data.other_allowances === '' ? null : data.other_allowances,
        }));

        const url = editingContract
            ? updateContract.url({
                  employee: employeeId,
                  employeeContract: editingContract.id,
              })
            : storeContract.url({ employee: employeeId });

        const options = {
            ...CONTRACTS_RELOAD,
            onSuccess: () => {
                setDialogOpen(false);
                contractForm.reset();
                setEditingContract(null);
                toast.success(
                    editingContract ? 'Contract updated.' : 'Contract added.',
                );
            },
        };

        if (editingContract) {
            contractForm.put(url, options);
        } else {
            contractForm.post(url, options);
        }
    };

    return (
        <TabsContent value="contract" className="mt-6">
            <EmployeeRecordsPanel
                title="Contracts"
                count={contracts.length}
                isEmpty={contracts.length === 0}
                emptyMessage="No contracts recorded yet."
                actions={
                    canManage ? (
                        <Button
                            size="sm"
                            className="h-8 gap-1.5 text-xs"
                            type="button"
                            onClick={openCreateDialog}
                        >
                            <Plus className="size-3.5" aria-hidden />
                            Add contract
                        </Button>
                    ) : undefined
                }
            >
                <EmployeeRecordsTable className="min-w-[960px]">
                    <thead>
                        <tr className={employeeRecordsTableHeadClass()}>
                            <th className={employeeRecordsTableThClass()}>Type</th>
                            <th className={employeeRecordsTableThClass()}>Start</th>
                            <th className={employeeRecordsTableThClass()}>End</th>
                            <th className={employeeRecordsTableThClass()}>Status</th>
                            <th className={employeeRecordsTableThClass()}>
                                Basic salary
                            </th>
                            <th className={employeeRecordsTableThClass()}>
                                Labor contract ID
                            </th>
                            {canManage ? <EmployeeRecordsActionsHeader /> : null}
                        </tr>
                    </thead>
                    <tbody>
                        {contracts.map((row) => (
                            <tr
                                key={row.id}
                                className={employeeRecordsTableRowClass()}
                            >
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'font-medium text-zinc-100',
                                    )}
                                >
                                    {formatContractType(row.contract_type)}
                                </td>
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'whitespace-nowrap text-zinc-400',
                                    )}
                                >
                                    {formatIsoDateDisplay(row.start_date)}
                                </td>
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'whitespace-nowrap text-zinc-400',
                                    )}
                                >
                                    {formatIsoDateDisplay(row.end_date)}
                                </td>
                                <td className={employeeRecordsTableTdClass()}>
                                    <span
                                        className={cn(
                                            'inline-flex rounded-full border px-2 py-0.5 text-xs font-medium',
                                            contractStatusClass(row.status),
                                        )}
                                    >
                                        {formatStatus(row.status)}
                                    </span>
                                </td>
                                <td className={employeeRecordsTableTdClass()}>
                                    {formatMoney(row.basic_salary)}
                                </td>
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'max-w-[200px] truncate',
                                    )}
                                    title={row.labor_contract_id ?? undefined}
                                >
                                    {row.labor_contract_id || '—'}
                                </td>
                                {canManage ? (
                                    <td
                                        className={cn(
                                            employeeRecordsTableTdClass(),
                                            'text-right',
                                        )}
                                    >
                                        <EmployeeRecordRowActions
                                            onEdit={() => openEditDialog(row)}
                                            onDelete={() => setDeleteContractId(row.id)}
                                        />
                                    </td>
                                ) : null}
                            </tr>
                        ))}
                    </tbody>
                </EmployeeRecordsTable>
            </EmployeeRecordsPanel>

            <Dialog
                open={dialogOpen}
                onOpenChange={(openDialog) => {
                    setDialogOpen(openDialog);

                    if (!openDialog) {
                        contractForm.reset();
                        contractForm.clearErrors();
                        setEditingContract(null);
                    }
                }}
            >
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>
                            {editingContract ? 'Edit contract' : 'Add contract'}
                        </DialogTitle>
                        <p className="text-xs text-zinc-500">
                            Fill in the contract details. Salary fields are optional.
                        </p>
                    </DialogHeader>

                    {/* Section: Contract details */}
                    <div className="space-y-4 py-1">
                        <div className="flex items-center gap-2">
                            <span className="text-[10px] font-semibold uppercase tracking-widest text-zinc-500">Contract details</span>
                            <div className="h-px flex-1 bg-white/5" />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label htmlFor="contract_type" className="text-xs">Contract type</Label>
                                <AppSelect
                                    value={contractForm.data.contract_type}
                                    onValueChange={(v) => contractForm.setData('contract_type', v)}
                                    variant="dark"
                                >
                                    <AppSelectItem value="unlimited">Unlimited</AppSelectItem>
                                    <AppSelectItem value="limited">Limited</AppSelectItem>
                                    <AppSelectItem value="part_time">Part time</AppSelectItem>
                                    <AppSelectItem value="contract">Contract</AppSelectItem>
                                </AppSelect>
                                <p className="text-[11px] text-zinc-500">The nature of the employment term</p>
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="contract_status" className="text-xs">Status</Label>
                                <AppSelect
                                    value={contractForm.data.status}
                                    onValueChange={(v) => contractForm.setData('status', v)}
                                    variant="dark"
                                >
                                    <AppSelectItem value="active">Active</AppSelectItem>
                                    <AppSelectItem value="ended">Ended</AppSelectItem>
                                    <AppSelectItem value="draft">Draft</AppSelectItem>
                                </AppSelect>
                                <p className="text-[11px] text-zinc-500">Current state of this contract</p>
                            </div>
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="contract_labor_contract_id" className="text-xs">Labor contract ID</Label>
                            <Input
                                id="contract_labor_contract_id"
                                className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                placeholder="e.g. MOL-2024-00123"
                                value={contractForm.data.labor_contract_id}
                                onChange={(e) => contractForm.setData('labor_contract_id', e.target.value)}
                            />
                            <p className="text-[11px] text-zinc-500">Reference number from the labor authority (optional)</p>
                        </div>
                    </div>

                    {/* Section: Duration */}
                    <div className="space-y-4 pt-2">
                        <div className="flex items-center gap-2">
                            <span className="text-[10px] font-semibold uppercase tracking-widest text-zinc-500">Duration</span>
                            <div className="h-px flex-1 bg-white/5" />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label htmlFor="contract_start_date" className="text-xs">Start date <span className="text-red-400">*</span></Label>
                                <Input
                                    id="contract_start_date"
                                    type="date"
                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                    value={contractForm.data.start_date}
                                    onChange={(e) => contractForm.setData('start_date', e.target.value)}
                                />
                                {contractForm.errors.start_date ? (
                                    <p className="text-xs text-destructive">{contractForm.errors.start_date}</p>
                                ) : (
                                    <p className="text-[11px] text-zinc-500">When the contract becomes effective</p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="contract_end_date" className="text-xs">End date</Label>
                                <Input
                                    id="contract_end_date"
                                    type="date"
                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                    value={contractForm.data.end_date}
                                    onChange={(e) => contractForm.setData('end_date', e.target.value)}
                                />
                                <p className="text-[11px] text-zinc-500">Leave blank for unlimited contracts</p>
                            </div>
                        </div>
                    </div>

                    {/* Section: Compensation */}
                    <div className="space-y-4 pt-2">
                        <div className="flex items-center gap-2">
                            <span className="text-[10px] font-semibold uppercase tracking-widest text-zinc-500">Compensation</span>
                            <div className="h-px flex-1 bg-white/5" />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label htmlFor="contract_basic_salary" className="text-xs">Basic salary</Label>
                                <Input
                                    id="contract_basic_salary"
                                    inputMode="decimal"
                                    placeholder="e.g. 5000.00"
                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                    value={contractForm.data.basic_salary}
                                    onChange={(e) => contractForm.setData('basic_salary', e.target.value)}
                                />
                                <p className="text-[11px] text-zinc-500">Monthly base salary in local currency</p>
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="contract_housing_allowance" className="text-xs">Housing allowance</Label>
                                <Input
                                    id="contract_housing_allowance"
                                    inputMode="decimal"
                                    placeholder="e.g. 1500.00"
                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                    value={contractForm.data.housing_allowance}
                                    onChange={(e) => contractForm.setData('housing_allowance', e.target.value)}
                                />
                                <p className="text-[11px] text-zinc-500">Monthly housing benefit (optional)</p>
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="contract_transport_allowance" className="text-xs">Transport allowance</Label>
                                <Input
                                    id="contract_transport_allowance"
                                    inputMode="decimal"
                                    placeholder="e.g. 500.00"
                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                    value={contractForm.data.transport_allowance}
                                    onChange={(e) => contractForm.setData('transport_allowance', e.target.value)}
                                />
                                <p className="text-[11px] text-zinc-500">Monthly transport benefit (optional)</p>
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="contract_other_allowances" className="text-xs">Other allowances</Label>
                                <Input
                                    id="contract_other_allowances"
                                    inputMode="decimal"
                                    placeholder="e.g. 200.00"
                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                    value={contractForm.data.other_allowances}
                                    onChange={(e) => contractForm.setData('other_allowances', e.target.value)}
                                />
                                <p className="text-[11px] text-zinc-500">Any additional monthly allowances (optional)</p>
                            </div>
                        </div>
                    </div>

                    <DialogFooter className="border-t border-white/5 pt-4">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="border-white/10 bg-white/5 text-zinc-300 hover:bg-white/10 hover:text-zinc-100"
                            onClick={() => setDialogOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            className="bg-indigo-600 text-white hover:bg-indigo-500"
                            disabled={contractForm.processing}
                            onClick={submitContract}
                        >
                            {contractForm.processing ? 'Saving…' : 'Save'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <EmployeeRecordDeleteDialog
                open={!!deleteContractId}
                onOpenChange={(openDialog) => {
                    if (!openDialog) {
                        setDeleteContractId(null);
                    }
                }}
                title="Remove contract?"
                description="This contract record will be permanently removed."
                destroyUrl={
                    deleteContractId
                        ? destroyContract.url({
                              employee: employeeId,
                              employeeContract: deleteContractId,
                          })
                        : null
                }
                reloadOptions={CONTRACTS_RELOAD}
                successMessage="Contract removed."
            />
        </TabsContent>
    );
}