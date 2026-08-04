import { AlertCircle, Building2, CreditCard, Users } from 'lucide-react';
import type React from 'react';
import { cn } from '@/lib/utils';
import type { EmployeeStats, PayrollShowFilters } from '../types';

export function EmployeeAnalyticsCardsGrid({
    employee_stats,
    activeEmployeeGroup,
    onEmployeeGroupSelect,
    activeCrewSalaryStructure = null,
}: {
    employee_stats: EmployeeStats;
    activeEmployeeGroup: PayrollShowFilters['employee_group'];
    onEmployeeGroupSelect: (
        employeeGroup: PayrollShowFilters['employee_group'],
    ) => void;
    activeCrewSalaryStructure?: 'daily' | 'monthly' | null;
}) {
    const totalSubtitle = activeCrewSalaryStructure
        ? `Active ${activeCrewSalaryStructure} crew on this pay run`
        : 'Active on this pay run';

    return (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <EmployeeAnalyticsCard
                title="Total Employees"
                value={employee_stats.total}
                subtitle={totalSubtitle}
                icon={Users}
                variant="total"
                isSelected={activeEmployeeGroup === ''}
                onClick={() => onEmployeeGroupSelect('')}
            />
            <EmployeeAnalyticsCard
                title="Bank Account Set"
                value={employee_stats.with_bank_account}
                subtitle={
                    activeCrewSalaryStructure
                        ? `Ready for ${activeCrewSalaryStructure} salary transfer`
                        : 'Ready for salary transfer'
                }
                icon={CreditCard}
                variant="success"
                isSelected={activeEmployeeGroup === 'with_bank_account'}
                onClick={() => onEmployeeGroupSelect('with_bank_account')}
            />
            <EmployeeAnalyticsCard
                title="Non-bank payment"
                value={employee_stats.cash_payment_count}
                subtitle="C3, Ansari, Cash, or third party"
                icon={Building2}
                variant={
                    employee_stats.cash_payment_count > 0
                        ? 'warning'
                        : 'success'
                }
                isSelected={activeEmployeeGroup === 'cash_payment'}
                onClick={() => onEmployeeGroupSelect('cash_payment')}
            />
            <EmployeeAnalyticsCard
                title="Missing Bank Account"
                value={employee_stats.missing_bank_account}
                subtitle={
                    employee_stats.missing_bank_account > 0
                        ? 'Bank-transfer employees only — action required before WPS'
                        : 'All bank-transfer employees configured'
                }
                icon={
                    employee_stats.missing_bank_account > 0
                        ? AlertCircle
                        : Building2
                }
                variant={
                    employee_stats.missing_bank_account > 0
                        ? 'warning'
                        : 'success'
                }
                isSelected={activeEmployeeGroup === 'missing_bank_account'}
                onClick={() => onEmployeeGroupSelect('missing_bank_account')}
            />
        </div>
    );
}

export function EmployeeAnalyticsCard({
    title,
    value,
    subtitle,
    icon: Icon,
    variant,
    isSelected = false,
    onClick,
}: {
    title: string;
    value: number;
    subtitle: string;
    icon: React.ElementType;
    variant: 'total' | 'success' | 'warning';
    isSelected?: boolean;
    onClick?: () => void;
}) {
    const styles = {
        total: {
            card: 'border-primary/20 bg-gradient-to-br from-primary/5 via-background to-background hover:border-primary/40 hover:shadow-primary/10',
            icon: 'bg-primary/10 border-primary/20 text-primary',
            value: 'text-primary',
            dot: 'bg-primary',
        },
        success: {
            card: 'border-emerald-500/20 bg-gradient-to-br from-emerald-500/5 via-background to-background hover:border-emerald-500/40 hover:shadow-emerald-500/10',
            icon: 'bg-emerald-500/10 border-emerald-500/20 text-emerald-600 dark:text-emerald-400',
            value: 'text-emerald-600 dark:text-emerald-400',
            dot: 'bg-emerald-500',
        },
        warning: {
            card: 'border-amber-500/20 bg-gradient-to-br from-amber-500/5 via-background to-background hover:border-amber-500/40 hover:shadow-amber-500/10',
            icon: 'bg-amber-500/10 border-amber-500/20 text-amber-600 dark:text-amber-400',
            value: 'text-amber-600 dark:text-amber-400',
            dot: 'bg-amber-500',
        },
    }[variant];

    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'group relative w-full overflow-hidden rounded-2xl border p-5 text-left transition-all duration-300 hover:-translate-y-0.5 hover:shadow-xl focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none',
                styles.card,
                isSelected && 'shadow-lg ring-2 ring-primary/50',
            )}
        >
            <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_var(--tw-gradient-stops))] from-white/10 via-transparent to-transparent opacity-40 dark:from-white/5" />

            <div className="relative z-10 flex items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                    <p className="text-[11px] font-bold tracking-[0.16em] text-muted-foreground/60 uppercase">
                        {title}
                    </p>
                    <p
                        className={cn(
                            'mt-2 text-3xl font-extrabold tracking-tight tabular-nums',
                            styles.value,
                        )}
                    >
                        {value.toLocaleString()}
                    </p>
                    <p className="mt-1.5 flex items-center gap-1.5 text-xs text-muted-foreground/70">
                        <span
                            className={cn(
                                'inline-block h-1.5 w-1.5 rounded-full',
                                styles.dot,
                            )}
                        />
                        {subtitle}
                    </p>
                </div>
                <div
                    className={cn(
                        'flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border shadow-inner transition-transform duration-300 group-hover:scale-110',
                        styles.icon,
                    )}
                >
                    <Icon className="h-5 w-5" />
                </div>
            </div>
        </button>
    );
}
