import { Briefcase } from 'lucide-react';
import type { ReactElement } from 'react';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { EmployeeSectionCard } from '@/pages/organization/_components/employee-section-card';
import {
    employeeFieldMissingHighlightClass,
    employeeFieldMissingLabelClass,
} from '@/pages/organization/_lib/employee-required-field-labels';

type WorkAssignmentOption = {
    id: number;
    name: string;
};

type EmployeeWorkAssignmentsSectionProps = {
    approvalLocations: WorkAssignmentOption[];
    sssaOptions: WorkAssignmentOption[];
    approvalLocationIds: number[];
    sssaOptionIds: number[];
    canUpdate: boolean;
    showApprovalLocations: boolean;
    showSssaOptions: boolean;
    highlightMissingApprovalLocations: boolean;
    highlightMissingSssaOptions: boolean;
    onApprovalLocationIdsChange: (ids: number[]) => void;
    onSssaOptionIdsChange: (ids: number[]) => void;
};

function toggleId(ids: number[], id: number, checked: boolean): number[] {
    if (checked) {
        return ids.includes(id) ? ids : [...ids, id];
    }

    return ids.filter((value) => value !== id);
}

function CheckboxGrid({
    options,
    selectedIds,
    disabled,
    onChange,
    highlightMissing,
    fieldKey,
}: {
    options: WorkAssignmentOption[];
    selectedIds: number[];
    disabled: boolean;
    onChange: (ids: number[]) => void;
    highlightMissing: boolean;
    fieldKey: string;
}): ReactElement {
    return (
        <div
            data-employee-field={fieldKey}
            className={cn(
                'grid gap-2 sm:grid-cols-2',
                highlightMissing && employeeFieldMissingHighlightClass,
            )}
        >
            {options.map((option) => {
                const checked = selectedIds.includes(option.id);

                return (
                    <label
                        key={option.id}
                        className={cn(
                            'flex items-center gap-3 rounded-xl border border-border/80 bg-muted/10 px-3 py-2.5 transition-colors dark:border-white/5 dark:bg-white/[0.02]',
                            !disabled &&
                                'cursor-pointer hover:bg-muted/30 dark:hover:bg-white/[0.04]',
                            disabled && 'opacity-70',
                        )}
                    >
                        <Checkbox
                            checked={checked}
                            disabled={disabled}
                            onCheckedChange={(value) =>
                                onChange(
                                    toggleId(
                                        selectedIds,
                                        option.id,
                                        value === true,
                                    ),
                                )
                            }
                        />
                        <span className="text-sm text-foreground">
                            {option.name}
                        </span>
                    </label>
                );
            })}
        </div>
    );
}

export function EmployeeWorkAssignmentsSection({
    approvalLocations,
    sssaOptions,
    approvalLocationIds,
    sssaOptionIds,
    canUpdate,
    showApprovalLocations,
    showSssaOptions,
    highlightMissingApprovalLocations,
    highlightMissingSssaOptions,
    onApprovalLocationIdsChange,
    onSssaOptionIdsChange,
}: EmployeeWorkAssignmentsSectionProps): ReactElement | null {
    if (!showApprovalLocations && !showSssaOptions) {
        return null;
    }

    return (
        <EmployeeSectionCard
            title="Work assignments"
            description="Approval locations and SSSA eligibility"
            icon={Briefcase}
            className="lg:col-span-3"
        >
            <div className="space-y-6">
                {showApprovalLocations ? (
                    <div className="space-y-3">
                        <Label
                            className={cn(
                                'text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase',
                                highlightMissingApprovalLocations &&
                                    employeeFieldMissingLabelClass,
                            )}
                        >
                            Approval locations
                        </Label>
                        <CheckboxGrid
                            options={approvalLocations}
                            selectedIds={approvalLocationIds}
                            disabled={!canUpdate}
                            onChange={onApprovalLocationIdsChange}
                            highlightMissing={highlightMissingApprovalLocations}
                            fieldKey="approval_location_ids"
                        />
                    </div>
                ) : null}

                {showSssaOptions ? (
                    <div className="space-y-3">
                        <Label
                            className={cn(
                                'text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase',
                                highlightMissingSssaOptions &&
                                    employeeFieldMissingLabelClass,
                            )}
                        >
                            SSSA
                        </Label>
                        <CheckboxGrid
                            options={sssaOptions}
                            selectedIds={sssaOptionIds}
                            disabled={!canUpdate}
                            onChange={onSssaOptionIdsChange}
                            highlightMissing={highlightMissingSssaOptions}
                            fieldKey="sssa_option_ids"
                        />
                    </div>
                ) : null}
            </div>
        </EmployeeSectionCard>
    );
}
