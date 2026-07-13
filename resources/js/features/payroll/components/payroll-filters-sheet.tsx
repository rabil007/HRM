import { AppSelect, AppSelectItem } from '@/components/app-select';
import { FiltersSheet } from '@/components/filters-sheet';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { CompanyVisaTypeOption } from '@/features/organization/employees/types';
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
    companyVisaTypes,
    value,
    onChange,
    onReset,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    payrollCategories: PayrollCategoryOption[];
    payrollPeriodStatuses: PayrollPeriodStatusOption[];
    companyVisaTypes: CompanyVisaTypeOption[];
    value: PayrollHubFilters;
    onChange: (next: PayrollHubFilters) => void;
    onReset: () => void;
}) {
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

            <div className="space-y-2">
                <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                    Sponsor
                </Label>
                <AppSelect
                    value={value.company_visa_type_id}
                    onValueChange={(next) =>
                        onChange({
                            ...value,
                            company_visa_type_id: next,
                        })
                    }
                    variant="dark"
                    placeholder="All"
                >
                    <AppSelectItem value="">All</AppSelectItem>
                    {companyVisaTypes.map((sponsor) => (
                        <AppSelectItem
                            key={sponsor.id}
                            value={String(sponsor.id)}
                        >
                            {sponsor.name}
                        </AppSelectItem>
                    ))}
                </AppSelect>
            </div>

            <div className="space-y-3">
                <div>
                    <p className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        Period dates
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground">
                        Show pay runs whose period overlaps the selected range.
                    </p>
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
                                onChange({ ...value, date_to: e.target.value })
                            }
                        />
                    </div>
                </div>
            </div>
        </FiltersSheet>
    );
}
