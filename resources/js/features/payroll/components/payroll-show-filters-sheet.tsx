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
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    companyVisaTypes: CompanyVisaTypeOption[];
    value: Pick<PayrollShowFilters, 'company_visa_type_id'>;
    onChange: (next: Pick<PayrollShowFilters, 'company_visa_type_id'>) => void;
    onReset: () => void;
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
        </FiltersSheet>
    );
}
