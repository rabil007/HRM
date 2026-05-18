import { router, useForm } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import {
    destroy as destroyContract,
    store as storeContract,
    update as updateContract,
} from '@/actions/App/Http/Controllers/Organization/EmployeeContractController';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
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
import { toast } from '@/lib/toast';
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
        probation_end_date: '',
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
            probation_end_date: '',
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
            probation_end_date: row.probation_end_date ?? '',
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
            probation_end_date:
                data.probation_end_date === '' ? null : data.probation_end_date,
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
            <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
                <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <h3 className="text-sm font-semibold text-zinc-200">
                        Contracts
                        <span className="ml-2 text-xs font-normal text-zinc-500">
                            {contracts.length} total
                        </span>
                    </h3>
                    {canManage ? (
                        <Button
                            size="sm"
                            className="h-8 gap-1.5 text-xs"
                            type="button"
                            onClick={openCreateDialog}
                        >
                            + Add contract
                        </Button>
                    ) : null}
                </div>

                {contracts.length === 0 ? (
                    <div className="py-10 text-center text-sm text-zinc-500">
                        No contracts recorded.
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[960px] text-left">
                            <thead>
                                <tr className="border-b border-white/5 text-xs font-semibold text-zinc-500">
                                    <th className="py-2 pr-4">Type</th>
                                    <th className="py-2 pr-4">Start</th>
                                    <th className="py-2 pr-4">End</th>
                                    <th className="py-2 pr-4">Status</th>
                                    <th className="py-2 pr-4">Basic salary</th>
                                    <th className="py-2 pr-4">Labor contract ID</th>
                                    {canManage ? (
                                        <th className="py-2 text-right">Actions</th>
                                    ) : null}
                                </tr>
                            </thead>
                            <tbody>
                                {contracts.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="border-b border-white/5 text-sm text-zinc-200"
                                    >
                                        <td className="py-3 pr-4">
                                            {formatContractType(row.contract_type)}
                                        </td>
                                        <td className="py-3 pr-4">
                                            {formatIsoDateDisplay(row.start_date)}
                                        </td>
                                        <td className="py-3 pr-4">
                                            {formatIsoDateDisplay(row.end_date)}
                                        </td>
                                        <td className="py-3 pr-4">
                                            <span
                                                className={
                                                    row.status === 'active'
                                                        ? 'text-emerald-400'
                                                        : 'text-zinc-400'
                                                }
                                            >
                                                {formatStatus(row.status)}
                                            </span>
                                        </td>
                                        <td className="py-3 pr-4">
                                            {formatMoney(row.basic_salary)}
                                        </td>
                                        <td className="py-3 pr-4">
                                            {row.labor_contract_id || '—'}
                                        </td>
                                        {canManage ? (
                                            <td className="py-3 text-right">
                                                <div className="flex justify-end gap-2">
                                                    <button
                                                        type="button"
                                                        className="text-xs text-zinc-400 transition-colors hover:text-white"
                                                        onClick={() =>
                                                            openEditDialog(row)
                                                        }
                                                    >
                                                        Edit
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="text-xs text-red-400/60 transition-colors hover:text-red-400"
                                                        onClick={() =>
                                                            setDeleteContractId(
                                                                row.id,
                                                            )
                                                        }
                                                    >
                                                        Delete
                                                    </button>
                                                </div>
                                            </td>
                                        ) : null}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

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
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {editingContract ? 'Edit contract' : 'Add contract'}
                        </DialogTitle>
                    </DialogHeader>
                    <div className="grid gap-4 py-2">
                        <div className="grid gap-2">
                            <Label htmlFor="contract_type">Contract type</Label>
                            <select
                                id="contract_type"
                                value={contractForm.data.contract_type}
                                onChange={(e) =>
                                    contractForm.setData(
                                        'contract_type',
                                        e.target.value,
                                    )
                                }
                                className="h-10 w-full rounded-md border border-white/10 bg-background px-3 text-sm"
                            >
                                <option value="unlimited">Unlimited</option>
                                <option value="limited">Limited</option>
                                <option value="part_time">Part time</option>
                                <option value="contract">Contract</option>
                            </select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="contract_status">Status</Label>
                            <select
                                id="contract_status"
                                value={contractForm.data.status}
                                onChange={(e) =>
                                    contractForm.setData('status', e.target.value)
                                }
                                className="h-10 w-full rounded-md border border-white/10 bg-background px-3 text-sm"
                            >
                                <option value="active">Active</option>
                                <option value="ended">Ended</option>
                                <option value="draft">Draft</option>
                            </select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="contract_start_date">Start date</Label>
                            <Input
                                id="contract_start_date"
                                type="date"
                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                value={contractForm.data.start_date}
                                onChange={(e) =>
                                    contractForm.setData(
                                        'start_date',
                                        e.target.value,
                                    )
                                }
                            />
                            {contractForm.errors.start_date ? (
                                <p className="text-xs text-destructive">
                                    {contractForm.errors.start_date}
                                </p>
                            ) : null}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="contract_end_date">End date</Label>
                            <Input
                                id="contract_end_date"
                                type="date"
                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                value={contractForm.data.end_date}
                                onChange={(e) =>
                                    contractForm.setData('end_date', e.target.value)
                                }
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="contract_probation_end_date">
                                Probation end date
                            </Label>
                            <Input
                                id="contract_probation_end_date"
                                type="date"
                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                value={contractForm.data.probation_end_date}
                                onChange={(e) =>
                                    contractForm.setData(
                                        'probation_end_date',
                                        e.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="contract_labor_contract_id">
                                Labor contract ID
                            </Label>
                            <Input
                                id="contract_labor_contract_id"
                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                value={contractForm.data.labor_contract_id}
                                onChange={(e) =>
                                    contractForm.setData(
                                        'labor_contract_id',
                                        e.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="contract_basic_salary">Basic salary</Label>
                            <Input
                                id="contract_basic_salary"
                                inputMode="decimal"
                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                value={contractForm.data.basic_salary}
                                onChange={(e) =>
                                    contractForm.setData(
                                        'basic_salary',
                                        e.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="contract_housing_allowance">
                                Housing allowance
                            </Label>
                            <Input
                                id="contract_housing_allowance"
                                inputMode="decimal"
                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                value={contractForm.data.housing_allowance}
                                onChange={(e) =>
                                    contractForm.setData(
                                        'housing_allowance',
                                        e.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="contract_transport_allowance">
                                Transport allowance
                            </Label>
                            <Input
                                id="contract_transport_allowance"
                                inputMode="decimal"
                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                value={contractForm.data.transport_allowance}
                                onChange={(e) =>
                                    contractForm.setData(
                                        'transport_allowance',
                                        e.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="contract_other_allowances">
                                Other allowances
                            </Label>
                            <Input
                                id="contract_other_allowances"
                                inputMode="decimal"
                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                value={contractForm.data.other_allowances}
                                onChange={(e) =>
                                    contractForm.setData(
                                        'other_allowances',
                                        e.target.value,
                                    )
                                }
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => setDialogOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            disabled={contractForm.processing}
                            onClick={submitContract}
                        >
                            {contractForm.processing ? 'Saving…' : 'Save'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <AlertDialog
                open={!!deleteContractId}
                onOpenChange={(openDialog) => {
                    if (!openDialog) {
                        setDeleteContractId(null);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Remove contract?</AlertDialogTitle>
                        <AlertDialogDescription>
                            This contract record will be permanently removed.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                            onClick={() => {
                                if (!deleteContractId) {
                                    return;
                                }

                                router.delete(
                                    destroyContract.url({
                                        employee: employeeId,
                                        employeeContract: deleteContractId,
                                    }),
                                    {
                                        ...CONTRACTS_RELOAD,
                                        onSuccess: () => {
                                            setDeleteContractId(null);
                                            toast.success('Contract removed.');
                                        },
                                    },
                                );
                            }}
                        >
                            Remove
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </TabsContent>
    );
}