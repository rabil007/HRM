import type { InertiaFormProps } from '@inertiajs/react';
import {
    Root as RadioGroup,
    Item as RadioItem,
} from '@radix-ui/react-radio-group';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Switch } from '@/components/ui/switch';
import { DocumentRequirementMultiSelect } from '@/features/organization/documents/configuration/document-requirement-multi-select';
import type {
    DepartmentOption,
    DocumentTypeFormData,
    DocumentTypeRow,
    PositionOption,
    ProjectOption,
    RankOption,
} from '@/features/organization/documents/configuration/types';
import { headerCheckboxState } from '@/lib/record-selection';
import { cn } from '@/lib/utils';

function policyFieldFlagsState(
    data: Pick<
        DocumentTypeFormData,
        'require_issue_date' | 'require_expiry_date' | 'require_document_number'
    >,
): boolean | 'indeterminate' {
    const selectedCount = [
        data.require_issue_date,
        data.require_expiry_date,
        data.require_document_number,
    ].filter(Boolean).length;

    return headerCheckboxState(selectedCount === 3, selectedCount > 0);
}

export function DocumentTypeFormSheet({
    open,
    onOpenChange,
    current,
    form,
    canUpdate,
    departments,
    positions,
    ranks,
    projects,
    onSubmit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    current: DocumentTypeRow | null;
    form: InertiaFormProps<DocumentTypeFormData>;
    canUpdate: boolean;
    departments: DepartmentOption[];
    positions: PositionOption[];
    ranks: RankOption[];
    projects: ProjectOption[];
    onSubmit: () => void;
}) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="flex w-full flex-col rounded-none glass-card p-0 sm:max-w-lg"
            >
                <SheetHeader className="border-b border-border/60 p-8 pb-6">
                    <SheetTitle className="text-xl font-bold tracking-tight">
                        {current ? 'Edit document type' : 'New document type'}
                    </SheetTitle>
                    <SheetDescription className="mt-1 text-sm text-muted-foreground/80">
                        Used for employee documents and ongoing compliance
                        requirements.
                    </SheetDescription>
                </SheetHeader>

                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        onSubmit();
                    }}
                    className="flex-1 space-y-8 overflow-y-auto p-8"
                >
                    {/* Section 1: Basics */}
                    <section className="space-y-4">
                        <div>
                            <h3 className="text-sm font-semibold tracking-tight text-foreground">
                                Basics
                            </h3>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Name and general availability of this document
                                type across the workspace.
                            </p>
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="title"
                                className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                            >
                                Document Type Name
                            </Label>
                            <Input
                                id="title"
                                value={form.data.title}
                                onChange={(event) =>
                                    form.setData('title', event.target.value)
                                }
                                placeholder="e.g. Passport Copy, Sea Service Book, Medical Fitness"
                                className="h-11 rounded-xl border-border bg-card"
                            />
                            {form.errors.title ? (
                                <div className="text-xs text-destructive">
                                    {form.errors.title}
                                </div>
                            ) : null}
                        </div>

                        <div className="flex items-center justify-between rounded-xl border border-border/80 bg-card/60 p-3.5 shadow-sm">
                            <div className="space-y-0.5">
                                <div className="text-sm font-semibold text-foreground">
                                    Active status
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    When inactive, this document type is hidden
                                    from upload dropdowns and templates.
                                </div>
                            </div>
                            <Switch
                                disabled={!canUpdate && !!current}
                                checked={form.data.is_active}
                                onCheckedChange={(value) =>
                                    form.setData('is_active', value)
                                }
                                aria-label="Toggle active status"
                            />
                        </div>
                    </section>

                    {/* Section 2: Requirement */}
                    <section className="space-y-4 border-t border-border/60 pt-6">
                        <div>
                            <h3 className="text-sm font-semibold tracking-tight text-foreground">
                                Requirement
                            </h3>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Is this document required for employees in your
                                company?
                            </p>
                        </div>

                        <RadioGroup
                            value={
                                form.data.is_required ? 'required' : 'optional'
                            }
                            onValueChange={(value) =>
                                form.setData(
                                    'is_required',
                                    value === 'required',
                                )
                            }
                            className="grid gap-2 sm:grid-cols-2"
                        >
                            <RadioItem
                                value="optional"
                                className={cn(
                                    'cursor-pointer rounded-xl border bg-card/70 p-3.5 text-left transition-all outline-none',
                                    !form.data.is_required
                                        ? 'border-primary shadow-xs ring-1 ring-primary'
                                        : 'border-border/80 hover:border-border hover:bg-card',
                                )}
                            >
                                <div className="text-sm font-semibold text-foreground">
                                    Optional
                                </div>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Document can be stored, but is not enforced
                                    as mandatory for compliance.
                                </p>
                            </RadioItem>
                            <RadioItem
                                value="required"
                                className={cn(
                                    'cursor-pointer rounded-xl border bg-card/70 p-3.5 text-left transition-all outline-none',
                                    form.data.is_required
                                        ? 'border-primary shadow-xs ring-1 ring-primary'
                                        : 'border-border/80 hover:border-border hover:bg-card',
                                )}
                            >
                                <div className="text-sm font-semibold text-foreground">
                                    Required document
                                </div>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Targeted employees must hold an active,
                                    valid file to stay compliant.
                                </p>
                            </RadioItem>
                        </RadioGroup>
                    </section>

                    {/* Section 3: Who needs this document? (Rendered when Required) */}
                    {form.data.is_required ? (
                        <section className="space-y-4 border-t border-border/60 pt-6">
                            <div>
                                <h3 className="text-sm font-semibold tracking-tight text-foreground">
                                    Who needs this document?
                                </h3>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Decide which employees are expected to hold
                                    this document.
                                </p>
                            </div>

                            <RadioGroup
                                value={
                                    form.data.required_for_all
                                        ? 'all'
                                        : 'selected'
                                }
                                onValueChange={(value) =>
                                    form.setData(
                                        'required_for_all',
                                        value === 'all',
                                    )
                                }
                                className="grid gap-2"
                            >
                                <RadioItem
                                    value="all"
                                    className={cn(
                                        'cursor-pointer rounded-xl border bg-card/70 p-3.5 text-left transition-all outline-none',
                                        form.data.required_for_all
                                            ? 'border-primary shadow-xs ring-1 ring-primary'
                                            : 'border-border/80 hover:border-border hover:bg-card',
                                    )}
                                >
                                    <div className="text-sm font-semibold text-foreground">
                                        All employees
                                    </div>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Mandatory for every active employee in
                                        this company.
                                    </p>
                                </RadioItem>
                                <RadioItem
                                    value="selected"
                                    className={cn(
                                        'cursor-pointer rounded-xl border bg-card/70 p-3.5 text-left transition-all outline-none',
                                        !form.data.required_for_all
                                            ? 'border-primary shadow-xs ring-1 ring-primary'
                                            : 'border-border/80 hover:border-border hover:bg-card',
                                    )}
                                >
                                    <div className="text-sm font-semibold text-foreground">
                                        Selected groups
                                    </div>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Applies only to employees matching
                                        specific departments, positions, ranks,
                                        or projects.
                                    </p>
                                </RadioItem>
                            </RadioGroup>
                            {form.errors.required_for_all ? (
                                <div className="text-xs text-destructive">
                                    {form.errors.required_for_all}
                                </div>
                            ) : null}

                            {!form.data.required_for_all ? (
                                <div className="space-y-4 pt-1">
                                    <div className="rounded-xl border border-primary/20 bg-primary/5 p-3.5 text-xs text-foreground/90">
                                        <p className="font-semibold text-primary">
                                            Matching rule
                                        </p>
                                        <p className="mt-1 leading-relaxed text-muted-foreground">
                                            Employees must match every selected
                                            category (
                                            <span className="font-medium text-foreground">
                                                AND
                                            </span>
                                            ). Within a category, matching any
                                            selected value is enough (
                                            <span className="font-medium text-foreground">
                                                OR
                                            </span>
                                            ). Unselected categories impose no
                                            restriction.
                                        </p>
                                    </div>

                                    <div className="space-y-4">
                                        <DocumentRequirementMultiSelect
                                            id="requirement-departments"
                                            label="Departments"
                                            options={departments.map(
                                                (department) => ({
                                                    id: department.id,
                                                    label: department.name,
                                                }),
                                            )}
                                            value={form.data.department_ids}
                                            onChange={(ids) =>
                                                form.setData(
                                                    'department_ids',
                                                    ids,
                                                )
                                            }
                                            error={form.errors.department_ids}
                                        />
                                        <DocumentRequirementMultiSelect
                                            id="requirement-positions"
                                            label="Positions"
                                            options={positions.map(
                                                (position) => ({
                                                    id: position.id,
                                                    label: position.title,
                                                }),
                                            )}
                                            value={form.data.position_ids}
                                            onChange={(ids) =>
                                                form.setData(
                                                    'position_ids',
                                                    ids,
                                                )
                                            }
                                            error={form.errors.position_ids}
                                        />
                                        <DocumentRequirementMultiSelect
                                            id="requirement-ranks"
                                            label="Ranks"
                                            options={ranks.map((rank) => ({
                                                id: rank.id,
                                                label: rank.name,
                                            }))}
                                            value={form.data.rank_ids}
                                            onChange={(ids) =>
                                                form.setData('rank_ids', ids)
                                            }
                                            error={form.errors.rank_ids}
                                        />
                                        <DocumentRequirementMultiSelect
                                            id="requirement-projects"
                                            label="Projects"
                                            options={projects.map(
                                                (project) => ({
                                                    id: project.id,
                                                    label: project.title,
                                                }),
                                            )}
                                            value={form.data.project_ids}
                                            onChange={(ids) =>
                                                form.setData('project_ids', ids)
                                            }
                                            error={form.errors.project_ids}
                                        />
                                    </div>
                                </div>
                            ) : null}
                        </section>
                    ) : null}

                    {/* Section 4: Tracked document details (Available when Required) */}
                    {form.data.is_required ? (
                        <section className="space-y-4 border-t border-border/60 pt-6">
                            <div>
                                <h3 className="text-sm font-semibold tracking-tight text-foreground">
                                    Tracked document details
                                </h3>
                                <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                                    Choose which details are relevant for this
                                    document type. These settings identify the
                                    details normally tracked for this document
                                    type. They do not currently make those
                                    fields mandatory during upload.
                                </p>
                            </div>

                            <div className="space-y-2.5 rounded-xl border border-border/80 bg-card/60 p-4">
                                <label className="flex cursor-pointer items-center gap-2.5 border-b border-border/60 pb-2.5 text-sm font-medium">
                                    <Checkbox
                                        checked={policyFieldFlagsState(
                                            form.data,
                                        )}
                                        onCheckedChange={(checked) => {
                                            const next = checked === true;

                                            form.setData({
                                                ...form.data,
                                                require_issue_date: next,
                                                require_expiry_date: next,
                                                require_document_number: next,
                                            });
                                        }}
                                        aria-label="Select all tracked details"
                                    />
                                    <span>Select all</span>
                                </label>
                                <label className="flex cursor-pointer items-center gap-2.5 text-sm">
                                    <Checkbox
                                        checked={form.data.require_issue_date}
                                        onCheckedChange={(checked) =>
                                            form.setData(
                                                'require_issue_date',
                                                checked === true,
                                            )
                                        }
                                    />
                                    <span>Issue date</span>
                                </label>
                                <div className="space-y-1">
                                    <label className="flex cursor-pointer items-center gap-2.5 text-sm">
                                        <Checkbox
                                            checked={
                                                form.data.require_expiry_date
                                            }
                                            onCheckedChange={(checked) =>
                                                form.setData(
                                                    'require_expiry_date',
                                                    checked === true,
                                                )
                                            }
                                        />
                                        <span>Expiry date</span>
                                    </label>
                                    <p className="pl-6 text-[11px] text-muted-foreground">
                                        Indicates that expiry date is a relevant
                                        detail for this document type.
                                    </p>
                                </div>
                                <label className="flex cursor-pointer items-center gap-2.5 text-sm">
                                    <Checkbox
                                        checked={
                                            form.data.require_document_number
                                        }
                                        onCheckedChange={(checked) =>
                                            form.setData(
                                                'require_document_number',
                                                checked === true,
                                            )
                                        }
                                    />
                                    <span>Document number</span>
                                </label>
                            </div>
                        </section>
                    ) : null}

                    <div className="flex items-center justify-end gap-2 border-t border-border/60 pt-4">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {current ? 'Save changes' : 'Create document type'}
                        </Button>
                    </div>
                </form>
            </SheetContent>
        </Sheet>
    );
}
