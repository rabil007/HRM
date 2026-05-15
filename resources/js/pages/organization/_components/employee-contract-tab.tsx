import type { ReactElement } from 'react';
import { Input } from '@/components/ui/input';
import { TabsContent } from '@/components/ui/tabs';
import type { EmployeeContractDetails } from '@/pages/organization/employee-page.types';

export type EmployeeContractFormSlice = {
    data: {
        contract_type: string;
        start_date: string;
        end_date: string;
        probation_end_date: string;
        labor_contract_id: string;
        basic_salary: string | number;
        housing_allowance: string | number;
        transport_allowance: string | number;
        other_allowances: string | number;
    } & Record<string, unknown>;
    errors: Record<string, string | undefined>;
    setData: (key: string, value: unknown) => void;
};

export type EmployeeContractTabProps = {
    contract: EmployeeContractDetails | null;
    form: EmployeeContractFormSlice;
    activeField: string | null;
    setActiveField: (v: string | null) => void;
    beginEdit: (field: string) => void;
    requiredDot: (field: string) => ReactElement | null;
};

export function EmployeeContractTab({
    contract,
    form,
    activeField,
    setActiveField,
    beginEdit,
    requiredDot,
}: EmployeeContractTabProps): ReactElement {
    return (
        <TabsContent value="contract" className="mt-6">
            <div className="grid grid-cols-1 gap-6 xl:grid-cols-12">
                <div className="space-y-6 xl:col-span-7">
                    <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
                        <div className="mb-4 flex items-center justify-between">
                            <h3 className="text-sm font-semibold text-zinc-200">
                                Contract
                            </h3>
                        </div>

                        <div className="space-y-4">
                            <div className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4">
                                <label className="text-xs font-medium text-zinc-400">
                                    Contract type
                                    {requiredDot('contract_type')}
                                </label>
                                {activeField === 'contract_type' ? (
                                    <div>
                                        <select
                                            className="h-10 w-full rounded-xl border border-white/5 bg-white/5 px-3 text-sm text-zinc-200 outline-none"
                                            value={form.data.contract_type}
                                            onChange={(e) =>
                                                form.setData(
                                                    'contract_type',
                                                    e.target.value,
                                                )
                                            }
                                            onBlur={() => setActiveField(null)}
                                            autoFocus
                                        >
                                            <option value="limited">
                                                Limited
                                            </option>
                                            <option value="unlimited">
                                                Unlimited
                                            </option>
                                            <option value="part_time">
                                                Part Time
                                            </option>
                                            <option value="contract">
                                                Contract
                                            </option>
                                        </select>
                                        {form.errors.contract_type ? (
                                            <div className="mt-1 text-xs text-destructive">
                                                {form.errors.contract_type}
                                            </div>
                                        ) : null}
                                    </div>
                                ) : (
                                    <button
                                        type="button"
                                        className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                        onClick={() =>
                                            beginEdit('contract_type')
                                        }
                                    >
                                        {form.data.contract_type ||
                                            contract?.contract_type ||
                                            '—'}
                                    </button>
                                )}
                            </div>

                            <div className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4">
                                <label className="text-xs font-medium text-zinc-400">
                                    Start date
                                    {requiredDot('start_date')}
                                </label>
                                {activeField === 'start_date' ? (
                                    <div>
                                        <Input
                                            type="date"
                                            className="h-10 rounded-xl border-white/5 bg-white/5"
                                            value={form.data.start_date}
                                            onChange={(e) =>
                                                form.setData(
                                                    'start_date',
                                                    e.target.value,
                                                )
                                            }
                                            onBlur={() => setActiveField(null)}
                                            autoFocus
                                        />
                                        {form.errors.start_date ? (
                                            <div className="mt-1 text-xs text-destructive">
                                                {form.errors.start_date}
                                            </div>
                                        ) : null}
                                    </div>
                                ) : (
                                    <button
                                        type="button"
                                        className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                        onClick={() => beginEdit('start_date')}
                                    >
                                        {form.data.start_date ||
                                            contract?.start_date ||
                                            '—'}
                                    </button>
                                )}
                            </div>

                            <div className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4">
                                <label className="text-xs font-medium text-zinc-400">
                                    End date
                                </label>
                                {activeField === 'end_date' ? (
                                    <div>
                                        <Input
                                            type="date"
                                            className="h-10 rounded-xl border-white/5 bg-white/5"
                                            value={form.data.end_date}
                                            onChange={(e) =>
                                                form.setData(
                                                    'end_date',
                                                    e.target.value,
                                                )
                                            }
                                            onBlur={() => setActiveField(null)}
                                            autoFocus
                                        />
                                        {form.errors.end_date ? (
                                            <div className="mt-1 text-xs text-destructive">
                                                {form.errors.end_date}
                                            </div>
                                        ) : null}
                                    </div>
                                ) : (
                                    <button
                                        type="button"
                                        className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                        onClick={() => beginEdit('end_date')}
                                    >
                                        {form.data.end_date ||
                                            contract?.end_date ||
                                            '—'}
                                    </button>
                                )}
                            </div>

                            <div className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4">
                                <label className="text-xs font-medium text-zinc-400">
                                    Probation end date
                                </label>
                                {activeField === 'probation_end_date' ? (
                                    <div>
                                        <Input
                                            type="date"
                                            className="h-10 rounded-xl border-white/5 bg-white/5"
                                            value={form.data.probation_end_date}
                                            onChange={(e) =>
                                                form.setData(
                                                    'probation_end_date',
                                                    e.target.value,
                                                )
                                            }
                                            onBlur={() => setActiveField(null)}
                                            autoFocus
                                        />
                                        {form.errors.probation_end_date ? (
                                            <div className="mt-1 text-xs text-destructive">
                                                {form.errors.probation_end_date}
                                            </div>
                                        ) : null}
                                    </div>
                                ) : (
                                    <button
                                        type="button"
                                        className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                        onClick={() =>
                                            beginEdit('probation_end_date')
                                        }
                                    >
                                        {form.data.probation_end_date ||
                                            contract?.probation_end_date ||
                                            '—'}
                                    </button>
                                )}
                            </div>

                            <div className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4">
                                <label className="text-xs font-medium text-zinc-400">
                                    Labor contract ID
                                </label>
                                {activeField === 'labor_contract_id' ? (
                                    <div>
                                        <Input
                                            className="h-10 rounded-xl border-white/5 bg-white/5"
                                            value={form.data.labor_contract_id}
                                            onChange={(e) =>
                                                form.setData(
                                                    'labor_contract_id',
                                                    e.target.value,
                                                )
                                            }
                                            onBlur={() => setActiveField(null)}
                                            autoFocus
                                        />
                                        {form.errors.labor_contract_id ? (
                                            <div className="mt-1 text-xs text-destructive">
                                                {form.errors.labor_contract_id}
                                            </div>
                                        ) : null}
                                    </div>
                                ) : (
                                    <button
                                        type="button"
                                        className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                        onClick={() =>
                                            beginEdit('labor_contract_id')
                                        }
                                    >
                                        {form.data.labor_contract_id ||
                                            contract?.labor_contract_id ||
                                            '—'}
                                    </button>
                                )}
                            </div>
                        </div>
                    </div>
                </div>

                <div className="space-y-6 xl:col-span-5">
                    <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
                        <div className="mb-4 flex items-center justify-between">
                            <h3 className="text-sm font-semibold text-zinc-200">
                                Salary
                            </h3>
                        </div>

                        <div className="space-y-4">
                            {(
                                [
                                    {
                                        key: 'basic_salary',
                                        label: 'Basic salary',
                                    },
                                    {
                                        key: 'housing_allowance',
                                        label: 'Housing allowance',
                                    },
                                    {
                                        key: 'transport_allowance',
                                        label: 'Transport allowance',
                                    },
                                    {
                                        key: 'other_allowances',
                                        label: 'Other allowances',
                                    },
                                ] as const
                            ).map((row) => (
                                <div
                                    key={row.key}
                                    className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4"
                                >
                                    <label className="text-xs font-medium text-zinc-400">
                                        {row.label}
                                    </label>
                                    {activeField === row.key ? (
                                        <div>
                                            <Input
                                                inputMode="decimal"
                                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                                value={String(
                                                    (form.data as any)[
                                                        row.key
                                                    ] ?? '',
                                                )}
                                                onChange={(e) =>
                                                    form.setData(
                                                        row.key as any,
                                                        e.target.value,
                                                    )
                                                }
                                                onBlur={() =>
                                                    setActiveField(null)
                                                }
                                                autoFocus
                                            />
                                            {(form.errors as any)[row.key] ? (
                                                <div className="mt-1 text-xs text-destructive">
                                                    {
                                                        (form.errors as any)[
                                                            row.key
                                                        ]
                                                    }
                                                </div>
                                            ) : null}
                                        </div>
                                    ) : (
                                        <button
                                            type="button"
                                            className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                            onClick={() => beginEdit(row.key)}
                                        >
                                            {String(
                                                (form.data as any)[row.key] ??
                                                    '',
                                            ) ||
                                                ((contract as any)?.[
                                                    row.key
                                                ] === null ||
                                                (contract as any)?.[row.key] ===
                                                    undefined
                                                    ? '—'
                                                    : String(
                                                          (contract as any)?.[
                                                              row.key
                                                          ],
                                                      ))}
                                        </button>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </TabsContent>
    );
}
