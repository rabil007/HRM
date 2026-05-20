import { useForm } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import {
    destroy as destroyBankAccount,
    store as storeBankAccount,
    update as updateBankAccount,
} from '@/actions/App/Http/Controllers/Organization/EmployeeBankAccountController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { EmployeeRecordRowActions } from '@/components/employee-record-row-actions';
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
import { EmployeeRecordDeleteDialog } from '@/features/organization/employees/profile/components/employee-record-delete-dialog';
import type { BankOption } from '@/features/organization/employees/types';
import { formatDisplayDate } from '@/lib/format-date';
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
import type { EmployeeBankAccountItem } from '@/pages/organization/employee-page.types';

const BANK_ACCOUNTS_RELOAD = {
    preserveScroll: true,
    only: ['bank_accounts', 'employee'],
} as const;

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
            <EmployeeRecordsPanel
                title="Bank accounts"
                count={bank_accounts.length}
                isEmpty={bank_accounts.length === 0}
                emptyMessage="No bank accounts recorded."
                actions={
                    canManage ? (
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
                                    is_primary: bank_accounts.length === 0 ? true : false,
                                });
                                setEditingRow(null);
                                setDialogOpen(true);
                            }}
                        >
                            + Add bank account
                        </Button>
                    ) : undefined
                }
            >
                <EmployeeRecordsTable className="min-w-[720px]">
                    <thead>
                        <tr className={employeeRecordsTableHeadClass()}>
                            <th className={employeeRecordsTableThClass()}>Bank</th>
                            <th className={employeeRecordsTableThClass()}>Account holder</th>
                            <th className={employeeRecordsTableThClass()}>IBAN</th>
                            <th className={cn(employeeRecordsTableThClass(), 'text-center')}>Primary</th>
                            <th className={employeeRecordsTableThClass()}>Added</th>
                            {canManage ? <EmployeeRecordsActionsHeader /> : null}
                        </tr>
                    </thead>
                    <tbody>
                        {bank_accounts.map((row) => (
                            <tr key={row.id} className={employeeRecordsTableRowClass()}>
                                <td
                                    className={cn(
                                        employeeRecordsTableTdClass(),
                                        'max-w-[200px] truncate font-medium text-zinc-100',
                                    )}
                                >
                                    {row.bank_name ?? '—'}
                                </td>
                                <td className={cn(employeeRecordsTableTdClass(), 'max-w-[180px] truncate')}>
                                    {row.account_name ?? '—'}
                                </td>
                                <td className={cn(employeeRecordsTableTdClass(), 'max-w-[240px] truncate font-mono text-xs')}>
                                    {row.iban ?? '—'}
                                </td>
                                <td className={cn(employeeRecordsTableTdClass(), 'text-center text-xs')}>
                                    {row.is_primary ? (
                                        <span className="text-emerald-400">✓</span>
                                    ) : (
                                        <span className="text-zinc-600">—</span>
                                    )}
                                </td>
                                <td className={cn(employeeRecordsTableTdClass(), 'whitespace-nowrap text-xs text-zinc-500')}>
                                    {formatDisplayDate(row.created_at)}
                                </td>
                                {canManage ? (
                                    <td className={cn(employeeRecordsTableTdClass(), 'text-right')}>
                                        <EmployeeRecordRowActions
                                            onEdit={() => {
                                                setEditingRow(row);
                                                bankForm.setData({
                                                    bank_id: row.bank_id ? String(row.bank_id) : '',
                                                    iban: row.iban ?? '',
                                                    account_name: row.account_name ?? '',
                                                    is_primary: row.is_primary,
                                                });
                                                bankForm.clearErrors();
                                                setDialogOpen(true);
                                            }}
                                            onDelete={() => setDeleteId(row.id)}
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
                        bankForm.reset();
                        bankForm.clearErrors();
                        setEditingRow(null);
                    }
                }}
            >
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {editingRow ? 'Edit bank account' : 'Add bank account'}
                        </DialogTitle>
                        <p className="text-xs text-zinc-500">
                            Enter the account details used for payroll disbursement.
                        </p>
                    </DialogHeader>

                    <div className="space-y-4 py-1">
                        {/* Section: Account details */}
                        <div className="flex items-center gap-2">
                            <span className="text-[10px] font-semibold uppercase tracking-widest text-zinc-500">Account details</span>
                            <div className="h-px flex-1 bg-white/5" />
                        </div>
                        <div className="space-y-1.5">
                            <Label className="text-xs">Bank <span className="text-red-400">*</span></Label>
                            <AppSelect
                                value={bankForm.data.bank_id}
                                onValueChange={(v) => bankForm.setData('bank_id', v)}
                                variant="dark"
                                placeholder="— Select a bank —"
                            >
                                <AppSelectItem value="">— Select a bank —</AppSelectItem>
                                {banks.map((bank) => (
                                    <AppSelectItem key={bank.id} value={String(bank.id)}>
                                        {bank.name}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            {bankForm.errors.bank_id ? (
                                <p className="text-xs text-destructive">{bankForm.errors.bank_id}</p>
                            ) : (
                                <p className="text-[11px] text-zinc-500">The financial institution holding this account</p>
                            )}
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label className="text-xs">Account holder <span className="text-red-400">*</span></Label>
                                <Input
                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                    placeholder="e.g. John M. Doe"
                                    value={bankForm.data.account_name}
                                    onChange={(e) => bankForm.setData('account_name', e.target.value)}
                                />
                                {bankForm.errors.account_name ? (
                                    <p className="text-xs text-destructive">{bankForm.errors.account_name}</p>
                                ) : (
                                    <p className="text-[11px] text-zinc-500">Full name as shown on the account</p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label className="text-xs">IBAN</Label>
                                <Input
                                    className="h-10 rounded-xl border-white/5 bg-white/5 font-mono text-sm"
                                    placeholder="e.g. AE07 0331 2345 6789 0123 456"
                                    value={bankForm.data.iban}
                                    onChange={(e) => bankForm.setData('iban', e.target.value)}
                                />
                                {bankForm.errors.iban ? (
                                    <p className="text-xs text-destructive">{bankForm.errors.iban}</p>
                                ) : (
                                    <p className="text-[11px] text-zinc-500">International bank account number (optional)</p>
                                )}
                            </div>
                        </div>

                        {/* Section: Settings */}
                        <div className="flex items-center gap-2 pt-1">
                            <span className="text-[10px] font-semibold uppercase tracking-widest text-zinc-500">Settings</span>
                            <div className="h-px flex-1 bg-white/5" />
                        </div>
                        <div className="rounded-xl border border-white/5 bg-white/[0.02] px-4 py-3">
                            <label className="flex items-center gap-3 text-sm text-zinc-200">
                                <Checkbox
                                    checked={bankForm.data.is_primary}
                                    disabled={editingRow !== null && bank_accounts.length === 1}
                                    onCheckedChange={(v) => bankForm.setData('is_primary', v === true)}
                                />
                                <div>
                                    <div className="font-medium">Primary payroll account</div>
                                    <div className="mt-0.5 text-[11px] text-zinc-500">Salary will be deposited to this account by default</div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <DialogFooter className="border-t border-white/5 pt-4">
                        <Button
                            variant="outline"
                            size="sm"
                            type="button"
                            className="border-white/10 bg-white/5 text-zinc-300 hover:bg-white/10 hover:text-zinc-100"
                            onClick={() => setDialogOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            size="sm"
                            type="button"
                            className="bg-indigo-600 text-white hover:bg-indigo-500"
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
                                        ...BANK_ACCOUNTS_RELOAD,
                                        onSuccess: () => {
                                            setDialogOpen(false);
                                            bankForm.reset();
                                            setEditingRow(null);
                                        },
                                    });
                                } else {
                                    bankForm.post(url, {
                                        ...BANK_ACCOUNTS_RELOAD,
                                        onSuccess: () => {
                                            setDialogOpen(false);
                                            bankForm.reset();
                                            setEditingRow(null);
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

            <EmployeeRecordDeleteDialog
                open={!!deleteId}
                onOpenChange={(openDialog) => {
                    if (!openDialog) {
                        setDeleteId(null);
                    }
                }}
                title="Remove bank account?"
                description="This entry will be permanently removed."
                destroyUrl={
                    deleteId
                        ? destroyBankAccount.url({
                              employee: employeeId,
                              bankAccount: deleteId,
                          })
                        : null
                }
                reloadOptions={BANK_ACCOUNTS_RELOAD}
            />
        </TabsContent>
    );
}
