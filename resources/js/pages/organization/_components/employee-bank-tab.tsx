import type { ReactElement } from 'react';
import { Input } from '@/components/ui/input';
import { TabsContent } from '@/components/ui/tabs';
import type { BankOption } from '@/features/organization/employees/types';
import type { EmployeeDetails } from '@/pages/organization/employee-page.types';

export type EmployeeBankFormSlice = {
    data: {
        bank_id: string;
        account_name: string;
        iban: string;
    } & Record<string, unknown>;
    errors: Record<string, string | undefined>;
    setData: (key: string, value: unknown) => void;
};

export type EmployeeBankTabProps = {
    employee: EmployeeDetails;
    banks: BankOption[];
    form: EmployeeBankFormSlice;
    activeField: string | null;
    setActiveField: (v: string | null) => void;
    beginEdit: (field: string) => void;
};

export function EmployeeBankTab({
    employee,
    banks,
    form,
    activeField,
    setActiveField,
    beginEdit,
}: EmployeeBankTabProps): ReactElement {
    return (
        <TabsContent value="bank" className="mt-6">
            <div className="grid grid-cols-1 gap-6 xl:grid-cols-12">
                <div className="space-y-6 xl:col-span-7">
                    <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
                        <div className="mb-4 flex items-center justify-between">
                            <h3 className="text-sm font-semibold text-zinc-200">
                                Bank account
                            </h3>
                        </div>

                        <div className="space-y-4">
                            <div className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4">
                                <label className="text-xs font-medium text-zinc-400">
                                    Bank
                                </label>
                                {activeField === 'bank_id' ? (
                                    <div>
                                        <select
                                            className="h-10 w-full rounded-xl border border-white/5 bg-white/5 px-3 text-sm text-zinc-200 outline-none"
                                            value={form.data.bank_id}
                                            onChange={(e) =>
                                                form.setData(
                                                    'bank_id',
                                                    e.target.value,
                                                )
                                            }
                                            onBlur={() => setActiveField(null)}
                                            autoFocus
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
                                        {form.errors.bank_id ? (
                                            <div className="mt-1 text-xs text-destructive">
                                                {form.errors.bank_id}
                                            </div>
                                        ) : null}
                                    </div>
                                ) : (
                                    <button
                                        type="button"
                                        className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                        onClick={() => beginEdit('bank_id')}
                                    >
                                        {banks.find(
                                            (bank) =>
                                                String(bank.id) ===
                                                String(
                                                    form.data.bank_id ||
                                                        employee.bank_id ||
                                                        '',
                                                ),
                                        )?.name ??
                                            employee.bank?.name ??
                                            '—'}
                                    </button>
                                )}
                            </div>

                            {[
                                {
                                    key: 'account_name',
                                    label: 'Account holder',
                                    value:
                                        form.data.account_name ||
                                        employee.account_name ||
                                        '—',
                                },
                                {
                                    key: 'iban',
                                    label: 'IBAN',
                                    value:
                                        form.data.iban || employee.iban || '—',
                                },
                            ].map((item) => (
                                <div
                                    key={item.key}
                                    className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4"
                                >
                                    <label className="text-xs font-medium text-zinc-400">
                                        {item.label}
                                    </label>
                                    {activeField === item.key ? (
                                        <div>
                                            <Input
                                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                                value={
                                                    (form.data as any)[item.key]
                                                }
                                                onChange={(e) =>
                                                    form.setData(
                                                        item.key as any,
                                                        e.target.value,
                                                    )
                                                }
                                                onBlur={() =>
                                                    setActiveField(null)
                                                }
                                                autoFocus
                                            />
                                            {(form.errors as any)[item.key] ? (
                                                <div className="mt-1 text-xs text-destructive">
                                                    {
                                                        (form.errors as any)[
                                                            item.key
                                                        ]
                                                    }
                                                </div>
                                            ) : null}
                                        </div>
                                    ) : (
                                        <button
                                            type="button"
                                            className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                            onClick={() => beginEdit(item.key)}
                                        >
                                            {item.value}
                                        </button>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                </div>

                <div className="space-y-6 xl:col-span-5">
                    <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
                        <div className="mb-4 flex items-center justify-between">
                            <h3 className="text-sm font-semibold text-zinc-200">
                                Payroll payment
                            </h3>
                        </div>

                        <div className="space-y-3">
                            <div className="rounded-xl border border-white/5 bg-black/10 p-4">
                                <div className="text-xs font-medium text-zinc-500">
                                    Primary bank
                                </div>
                                <div className="mt-1 text-sm font-semibold text-zinc-200">
                                    {banks.find(
                                        (bank) =>
                                            String(bank.id) ===
                                            String(
                                                form.data.bank_id ||
                                                    employee.bank_id ||
                                                    '',
                                            ),
                                    )?.name ??
                                        employee.bank?.name ??
                                        'Not selected'}
                                </div>
                            </div>
                            <div className="rounded-xl border border-white/5 bg-black/10 p-4">
                                <div className="text-xs font-medium text-zinc-500">
                                    Account holder
                                </div>
                                <div className="mt-1 text-sm font-semibold text-zinc-200">
                                    {form.data.account_name ||
                                        employee.account_name ||
                                        '—'}
                                </div>
                            </div>
                            <div className="rounded-xl border border-white/5 bg-black/10 p-4">
                                <div className="text-xs font-medium text-zinc-500">
                                    IBAN
                                </div>
                                <div className="mt-1 font-mono text-sm font-semibold break-all text-zinc-200">
                                    {form.data.iban || employee.iban || '—'}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </TabsContent>
    );
}
