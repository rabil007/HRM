import { router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
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
                                <select
                                    id="contract_type"
                                    value={contractForm.data.contract_type}
                                    onChange={(e) => contractForm.setData('contract_type', e.target.value)}
                                    className="h-10 w-full rounded-xl border border-white/10 bg-white/5 px-3 text-sm text-zinc-100 outline-none focus:ring-1 focus:ring-primary"
                                >
                                    <option value="unlimited">Unlimited</option>
                                    <option value="limited">Limited</option>
                                    <option value="part_time">Part time</option>
                                    <option value="contract">Contract</option>
                                </select>
                                <p className="text-[11px] text-zinc-500">The nature of the employment term</p>
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="contract_status" className="text-xs">Status</Label>
                                <select
                                    id="contract_status"
                                    value={contractForm.data.status}
                                    onChange={(e) => contractForm.setData('status', e.target.value)}
                                    className="h-10 w-full rounded-xl border border-white/10 bg-white/5 px-3 text-sm text-zinc-100 outline-none focus:ring-1 focus:ring-primary"
                                >
                                    <option value="active">Active</option>
                                    <option value="ended">Ended</option>
                                    <option value="draft">Draft</option>
                                </select>
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

            <AlertDialog
                open={!!deleteContractId}
                onOpenChange={(openDialog) => {
                    if (!openDialog) {
                        setDeleteContractId(null);
                    }
                }}
            >
                <AlertDialogContent className="sm:max-w-sm">
                    <AlertDialogHeader>
                        <div className="mb-1 flex items-center gap-3">
                            <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-red-500/10 text-red-400">
                                <Trash2 className="size-4" />
                            </span>
                            <AlertDialogTitle>Remove contract?</AlertDialogTitle>
                        </div>
                        <AlertDialogDescription>
                            This contract record will be permanently removed.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel className="border-white/10 bg-white/5 text-zinc-300 hover:bg-white/10 hover:text-zinc-100">Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            className="bg-red-600 text-white hover:bg-red-500"
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