import { AppSelect, AppSelectItem } from '@/components/app-select';
import { FiltersSheet } from '@/components/filters-sheet';
import { Label } from '@/components/ui/label';
import type {
    CompanyVisaTypeOption,
    PositionOption,
} from '@/features/organization/employees/types';

export type BulkDocumentFilters = {
    department_id: string;
    position_id: string;
    status: string;
    company_visa_type_id: string;
};

export const EMPTY_BULK_DOCUMENT_FILTERS: BulkDocumentFilters = {
    department_id: '',
    position_id: '',
    status: 'active',
    company_visa_type_id: '',
};

const STATUS_OPTIONS = [
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
    { value: 'terminated', label: 'Terminated' },
    { value: '', label: 'All statuses' },
];

export function BulkDocumentsFiltersSheet({
    open,
    onOpenChange,
    value,
    onChange,
    onReset,
    positions,
    companyVisaTypes,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    value: BulkDocumentFilters;
    onChange: (next: BulkDocumentFilters) => void;
    onReset: () => void;
    positions: PositionOption[];
    companyVisaTypes: CompanyVisaTypeOption[];
}) {
    return (
        <FiltersSheet open={open} onOpenChange={onOpenChange} onReset={onReset}>
            <div className="grid gap-5">
                <div className="space-y-2">
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        Status
                    </Label>
                    <AppSelect
                        value={value.status}
                        onValueChange={(status) => onChange({ ...value, status })}
                    >
                        {STATUS_OPTIONS.map((option) => (
                            <AppSelectItem
                                key={option.value || 'all'}
                                value={option.value}
                            >
                                {option.label}
                            </AppSelectItem>
                        ))}
                    </AppSelect>
                </div>

                <div className="space-y-2">
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        Position
                    </Label>
                    <AppSelect
                        value={value.position_id}
                        onValueChange={(position_id) =>
                            onChange({ ...value, position_id })
                        }
                    >
                        <AppSelectItem value="">All positions</AppSelectItem>
                        {positions.map((position) => (
                            <AppSelectItem
                                key={position.id}
                                value={String(position.id)}
                            >
                                {position.title}
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
                        onValueChange={(company_visa_type_id) =>
                            onChange({ ...value, company_visa_type_id })
                        }
                    >
                        <AppSelectItem value="">All sponsors</AppSelectItem>
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
            </div>
        </FiltersSheet>
    );
}
