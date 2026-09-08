import { AppSelect, AppSelectItem } from '@/components/app-select';
import { FiltersSheet } from '@/components/filters-sheet';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type {
    PayrollCategoryOption,
    PayrollHubFilters,
    PayrollPeriodStatusOption,
} from '../types';

export function PayrollFiltersSheet({
    open,
    onOpenChange,
    payrollCategories,
    payrollPeriodStatuses,
    value,
    onChange,
    onReset,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    payrollCategories: PayrollCategoryOption[];
    payrollPeriodStatuses: PayrollPeriodStatusOption[];
    value: PayrollHubFilters;
    onChange: (next: PayrollHubFilters) => void;
    onReset: () => void;
}) {
    const setThisMonth = () => {
        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const lastDay = new Date(year, now.getMonth() + 1, 0).getDate();
        onChange({
            ...value,
            date_from: `${year}-${month}-01`,
            date_to: `${year}-${month}-${String(lastDay).padStart(2, '0')}`,
            all: '',
        });
    };

    const setAllPeriods = () => {
        onChange({
            ...value,
            date_from: '',
            date_to: '',
            all: '1',
        });
    };

    return (
        <FiltersSheet open={open} onOpenChange={onOpenChange} onReset={onReset}>
            <div className="space-y-2">
                <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                    Payroll type
                </Label>
                <AppSelect
                    value={value.category}
                    onValueChange={(next) =>
                        onChange({
                            ...value,
                            category: next as PayrollHubFilters['category'],
                        })
                    }
                    variant="dark"
                    placeholder="All"
                >
                    <AppSelectItem value="">All</AppSelectItem>
                    {payrollCategories.map((category) => (
                        <AppSelectItem
                            key={category.value}
                            value={category.value}
                        >
                            {category.label}
                        </AppSelectItem>
                    ))}
                </AppSelect>
            </div>

            <div className="space-y-2">
                <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                    Status
                </Label>
                <AppSelect
                    value={value.status}
                    onValueChange={(next) =>
                        onChange({
                            ...value,
                            status: next as PayrollHubFilters['status'],
                        })
                    }
                    variant="dark"
                    placeholder="All"
                >
                    <AppSelectItem value="">All</AppSelectItem>
                    {payrollPeriodStatuses.map((status) => (
                        <AppSelectItem key={status.value} value={status.value}>
                            {status.label}
                        </AppSelectItem>
                    ))}
                </AppSelect>
            </div>

            <div className="space-y-3">
                <div className="flex items-center justify-between">
                    <div>
                        <p className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                            Period dates
                        </p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            Show pay runs whose period overlaps the range.
                        </p>
                    </div>
                </div>

                <div className="flex items-center gap-2">
                    <Button
                        type="button"
                        variant={
                            !value.all && value.date_from ? 'secondary' : 'outline'
                        }
                        size="sm"
                        onClick={setThisMonth}
                        className="h-8 rounded-lg text-xs"
                    >
                        This month
                    </Button>
                    <Button
                        type="button"
                        variant={value.all === '1' ? 'secondary' : 'ghost'}
                        size="sm"
                        onClick={setAllPeriods}
                        className="h-8 rounded-lg text-xs"
                    >
                        All periods
                    </Button>
                </div>

                <div className="grid grid-cols-2 gap-4">
                    <div className="space-y-2">
                        <Label
                            htmlFor="payroll-filter-date-from"
                            className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                        >
                            From
                        </Label>
                        <Input
                            id="payroll-filter-date-from"
                            type="date"
                            className="h-11 rounded-xl border-white/10 bg-white/5 transition-all focus-visible:ring-primary/40"
                            value={value.date_from}
                            onChange={(e) =>
                                onChange({
                                    ...value,
                                    date_from: e.target.value,
                                    all: '',
                                })
                            }
                        />
                    </div>
                    <div className="space-y-2">
                        <Label
                            htmlFor="payroll-filter-date-to"
                            className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                        >
                            To
                        </Label>
                        <Input
                            id="payroll-filter-date-to"
                            type="date"
                            className="h-11 rounded-xl border-white/10 bg-white/5 transition-all focus-visible:ring-primary/40"
                            value={value.date_to}
                            onChange={(e) =>
                                onChange({
                                    ...value,
                                    date_to: e.target.value,
                                    all: '',
                                })
                            }
                        />
                    </div>
                </div>
            </div>
        </FiltersSheet>
    );
}
