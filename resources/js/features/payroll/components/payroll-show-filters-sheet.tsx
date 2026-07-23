import { AppSelect, AppSelectItem } from '@/components/app-select';
import { FiltersSheet } from '@/components/filters-sheet';
import { Label } from '@/components/ui/label';
import type { CompanyVisaTypeOption } from '@/features/organization/employees/types';
import type { PayrollShowFilters } from '../types';

export function PayrollShowFiltersSheet({
    open,
    onOpenChange,
    companyVisaTypes,
    value,
    onChange,
    onReset,
    supportsTimesheets = false,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    companyVisaTypes: CompanyVisaTypeOption[];
    value: Pick<
        PayrollShowFilters,
        'company_visa_type_id' | 'crew_timesheet_filter'
    >;
    onChange: (
        next: Pick<
            PayrollShowFilters,
            'company_visa_type_id' | 'crew_timesheet_filter'
        >,
    ) => void;
    onReset: () => void;
    supportsTimesheets?: boolean;
}) {
    return (
        <FiltersSheet open={open} onOpenChange={onOpenChange} onReset={onReset}>
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
            {supportsTimesheets ? (
                <div className="space-y-2">
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        Timesheet coverage
                    </Label>
                    <AppSelect
                        value={value.crew_timesheet_filter ?? ''}
                        onValueChange={(next) =>
                            onChange({
                                ...value,
                                crew_timesheet_filter: next,
                            })
                        }
                        variant="dark"
                        placeholder="All"
                    >
                        <AppSelectItem value="">All</AppSelectItem>
                        <AppSelectItem value="ready">Ready</AppSelectItem>
                        <AppSelectItem value="missing_timesheet">
                            Missing Timesheet
                        </AppSelectItem>
                        <AppSelectItem value="awaiting_approval">
                            Awaiting Approval
                        </AppSelectItem>
                        <AppSelectItem value="crew_operations">
                            Crew Operations
                        </AppSelectItem>
                        <AppSelectItem value="manual">Manual</AppSelectItem>
                        <AppSelectItem value="import">Import</AppSelectItem>
                        <AppSelectItem value="returned">Returned</AppSelectItem>
                    </AppSelect>
                </div>
            ) : null}
        </FiltersSheet>
    );
}
