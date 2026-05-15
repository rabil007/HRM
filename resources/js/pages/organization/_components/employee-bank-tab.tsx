import { router, useForm } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import {
    destroy as destroyBankAccount,
    store as storeBankAccount,
    update as updateBankAccount,
} from '@/actions/App/Http/Controllers/Organization/EmployeeBankAccountController';
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
import { Checkbox } from '@/components/ui/checkbox';
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
import type { BankOption } from '@/features/organization/employees/types';
import { toast } from '@/lib/toast';
import type { EmployeeBankAccountItem } from '@/pages/organization/employee-page.types';

export type EmployeeBankTabProps = {
    employeeId: number;
    bank_accounts: EmployeeBankAccountItem[];
    banks: BankOption[];
    canManage: boolean;
};

export function EmployeeBankTab({
    employeeId,
    bank_accounts,
    banks,
    canManage,
}: EmployeeBankTabProps): ReactElement {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editingRow, setEditingRow] = useState<EmployeeBankAccountItem | null>(
        null,
    );
    const [deleteId, setDeleteId] = useState<number | null>(null);

    const bankForm = useForm({
        bank_id: '',
        iban: '',
        account_name: '',
        is_primary: false,
    });

    return (
        <TabsContent value="bank" className="mt-6">
            <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
                <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <h3 className="text-sm font-semibold text-zinc-200">
                        Bank accounts
                        <span className="ml-2 text-xs font-normal text-zinc-500">
                            {bank_accounts.length} total
                        </span>
                    </h3>
                    {canManage ? (
                        <Button
                            size="sm"
                            className="h-8 gap-1.5 text-xs"
                            type="button"
                            onClick={() => {
                                bankForm.reset();
                                bankForm.clearErrors();
                                bankForm.setData({
                                    bank_id: '',
                                    iban: '',
                                    account_name: '',
                                    is_primary:
                                        bank_accounts.length === 0
                                            ? true
                                            : false,
                                });
                                setEditingRow(null);
                                setDialogOpen(true);
                            }}
                        >
                            + Add bank account
                        </Button>
                    ) : null}
                </div>

                {bank_accounts.length === 0 ? (
                    <div className="py-10 text-center text-sm text-zinc-500">
                        No bank accounts recorded.
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[720px] text-left">
                            <thead>
                                <tr className="border-b border-white/5 text-xs font-semibold text-zinc-500">
                                    <th className="py-2 pr-4">Bank</th>
                                    <th className="py-2 pr-4">Account holder</th>
                                    <th className="py-2 pr-4">IBAN</th>
                                    <th className="py-2 pr-4 text-center">
                                        Primary
                                    </th>
                                    <th className="py-2 pr-4">Added</th>
                                    {canManage ? (
                                        <th className="py-2 pr-4 text-right" />
                                    ) : null}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-white/5">
                                {bank_accounts.map((row) => (
                                    <tr key={row.id} className="text-sm text-zinc-200">
                                        <td className="max-w-[200px] truncate py-3 pr-4 font-medium">
                                            {row.bank_name ?? '—'}
                                        </td>
                                        <td className="max-w-[180px] truncate py-3 pr-4">
                                            {row.account_name ?? '—'}
                                        </td>
                                        <td className="max-w-[240px] truncate py-3 pr-4 font-mono text-xs">
                                            {row.iban ?? '—'}
                                        </td>
                                        <td className="py-3 pr-4 text-center text-xs">
                                            {row.is_primary ? (
                                                <span className="text-emerald-400">
                                                    ✓
                                                </span>
                                            ) : (
                                                <span className="text-zinc-600">
                                                    —
                                                </span>
                                            )}
                                        </td>
                                        <td className="whitespace-nowrap py-3 pr-4 text-xs text-zinc-500">
                                            {new Date(
                                                row.created_at,
                                            ).toLocaleString(undefined, {
                                                month: 'short',
                                                day: 'numeric',
                                                hour: 'numeric',
                                                minute: '2-digit',
                                            })}
                                        </td>
                                        {canManage ? (
                                            <td className="py-3 pr-0 text-right">
                                                <div className="flex items-center justify-end gap-2">
                                                    <button
                                                        type="button"
                                                        className="text-xs text-zinc-400 transition-colors hover:text-zinc-200"
                                                        onClick={() => {
                                                            setEditingRow(row);
                                                            bankForm.setData({
                                                                bank_id: row.bank_id
                                                                    ? String(
                                                                          row.bank_id,
                                                                      )
                                                                    : '',
                                                                iban:
                                                                    row.iban ??
                                                                    '',
                                                                account_name:
                                                                    row.account_name ??
                                                                    '',
                                                                is_primary:
                                                                    row.is_primary,
                                                            });
                                                            bankForm.clearErrors();
                                                            setDialogOpen(true);
                                                        }}
                                                    >
                                                        Edit
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="text-xs text-red-400/60 transition-colors hover:text-red-400"
                                                        onClick={() =>
                                                            setDeleteId(row.id)
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
                        bankForm.reset();
                        bankForm.clearErrors();
                        setEditingRow(null);
                    }
                }}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {editingRow
                                ? 'Edit bank account'
                                : 'Add bank account'}
                        </DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4 py-2">
                        <div className="space-y-1.5">
                            <Label className="text-xs">Bank</Label>
                            <select
                                className="flex h-10 w-full rounded-xl border border-white/10 bg-black/30 px-3 text-sm text-zinc-200 outline-none ring-offset-background focus-visible:ring-2 focus-visible:ring-ring"
                                value={bankForm.data.bank_id}
                                onChange={(e) =>
                                    bankForm.setData('bank_id', e.target.value)
                                }
                            >
                                <option value="">—</option>
                                {banks.map((bank) => (
                                    <option
                                        key={bank.id}
                                        value={String(bank.id)}
                                    >
                                        {bank.name}
                                    </option>
                                ))}
                            </select>
                            {bankForm.errors.bank_id ? (
                                <p className="text-xs text-destructive">
                                    {bankForm.errors.bank_id}
                                </p>
                            ) : null}
                        </div>
                        <div className="space-y-1.5">
                            <Label className="text-xs">Account holder</Label>
                            <Input
                                className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                value={bankForm.data.account_name}
                                onChange={(e) =>
                                    bankForm.setData(
                                        'account_name',
                                        e.target.value,
                                    )
                                }
                            />
                            {bankForm.errors.account_name ? (
                                <p className="text-xs text-destructive">
                                    {bankForm.errors.account_name}
                                </p>
                            ) : null}
                        </div>
                        <div className="space-y-1.5">
                            <Label className="text-xs">IBAN</Label>
                            <Input
                                className="h-10 rounded-xl border-white/5 bg-white/5 font-mono text-sm"
                                value={bankForm.data.iban}
                                onChange={(e) =>
                                    bankForm.setData('iban', e.target.value)
                                }
                            />
                            {bankForm.errors.iban ? (
                                <p className="text-xs text-destructive">
                                    {bankForm.errors.iban}
                                </p>
                            ) : null}
                        </div>
                        <label className="flex items-center gap-2 text-sm text-zinc-200">
                            <Checkbox
                                checked={bankForm.data.is_primary}
                                disabled={
                                    editingRow !== null &&
                                    bank_accounts.length === 1
                                }
                                onCheckedChange={(v) =>
                                    bankForm.setData('is_primary', v === true)
                                }
                            />
                            Primary payroll account
                        </label>
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            size="sm"
                            type="button"
                            onClick={() => setDialogOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            size="sm"
                            type="button"
                            disabled={bankForm.processing}
                            onClick={() => {
                                bankForm.clearErrors();
                                bankForm.transform((data) => ({
                                    bank_id: data.bank_id
                                        ? Number(data.bank_id)
                                        : null,
                                    iban: data.iban?.trim() || null,
                                    account_name:
                                        data.account_name?.trim() || null,
                                    is_primary: !!data.is_primary,
                                }));

                                const url = editingRow
                                    ? updateBankAccount.url({
                                          employee: employeeId,
                                          bankAccount: editingRow.id,
                                      })
                                    : storeBankAccount.url({
                                          employee: employeeId,
                                      });

                                if (editingRow) {
                                    bankForm.put(url, {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            setDialogOpen(false);
                                            bankForm.reset();
                                            setEditingRow(null);
                                            toast.success(
                                                'Bank account updated.',
                                            );
                                        },
                                    });
                                } else {
                                    bankForm.post(url, {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            setDialogOpen(false);
                                            bankForm.reset();
                                            setEditingRow(null);
                                            toast.success(
                                                'Bank account added.',
                                            );
                                        },
                                    });
                                }
                            }}
                        >
                            {bankForm.processing ? 'Saving…' : 'Save'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <AlertDialog
                open={!!deleteId}
                onOpenChange={(openDialog) => {
                    if (!openDialog) {
                        setDeleteId(null);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Remove bank account?</AlertDialogTitle>
                        <AlertDialogDescription>
                            This entry will be permanently removed.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                            onClick={() => {
                                if (!deleteId) {
                                    return;
                                }

                                router.delete(
                                    destroyBankAccount.url({
                                        employee: employeeId,
                                        bankAccount: deleteId,
                                    }),
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            setDeleteId(null);
                                            toast.success(
                                                'Bank account removed.',
                                            );
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
