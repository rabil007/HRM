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
                    <section className="space-y-5">
                        <div>
                            <h3 className="text-sm font-semibold tracking-tight">
                                General
                            </h3>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Document type label and whether it appears in
                                dropdowns.
                            </p>
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="title"
                                className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                            >
                                Document Type
                            </Label>
                            <Input
                                id="title"
                                value={form.data.title}
                                onChange={(event) =>
                                    form.setData('title', event.target.value)
                                }
                                className="h-11 rounded-xl border-border bg-card"
                            />
                            {form.errors.title ? (
                                <div className="text-xs text-destructive">
                                    {form.errors.title}
                                </div>
                            ) : null}
                        </div>

                        <div className="flex items-center justify-between rounded-xl border border-border bg-card p-3">
                            <div>
                                <div className="text-sm font-semibold">
                                    Active
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    Visible in dropdowns and templates.
                                </div>
                            </div>
                            <Switch
                                disabled={!canUpdate && !!current}
                                checked={form.data.is_active}
                                onCheckedChange={(value) =>
                                    form.setData('is_active', value)
                                }
                            />
                        </div>
                    </section>

                    <section className="space-y-5">
                        <div>
                            <h3 className="text-sm font-semibold tracking-tight">
                                Employee Requirement
                            </h3>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Company-specific compliance rules. Changing an
                                employee&apos;s department, position, rank, or
                                project updates who must hold this document.
                            </p>
                        </div>

                        <div className="space-y-2">
                            <p className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                Requirement
                            </p>
                            <RadioGroup
                                value={
                                    form.data.is_required
                                        ? 'required'
                                        : 'optional'
                                }
                                onValueChange={(value) =>
                                    form.setData(
                                        'is_required',
                                        value === 'required',
                                    )
                                }
                                className="grid gap-2"
                            >
                                <RadioItem
                                    value="optional"
                                    className={cn(
                                        'rounded-xl border bg-card p-3 text-left outline-none',
                                        !form.data.is_required
                                            ? 'border-primary ring-1 ring-primary'
                                            : 'border-border',
                                    )}
                                >
                                    <div className="text-sm font-semibold">
                                        Optional
                                    </div>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Not tracked as a required compliance
                                        document.
                                    </p>
                                </RadioItem>
                                <RadioItem
                                    value="required"
                                    className={cn(
                                        'rounded-xl border bg-card p-3 text-left outline-none',
                                        form.data.is_required
                                            ? 'border-primary ring-1 ring-primary'
                                            : 'border-border',
                                    )}
                                >
                                    <div className="text-sm font-semibold">
                                        Required document
                                    </div>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Employees matching the scope below must
                                        hold a current file.
                                    </p>
                                </RadioItem>
                            </RadioGroup>
                        </div>

                        {form.data.is_required ? (
                            <>
                                <div className="space-y-2">
                                    <p className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                        Required For
                                    </p>
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
                                                'rounded-xl border bg-card p-3 text-left outline-none',
                                                form.data.required_for_all
                                                    ? 'border-primary ring-1 ring-primary'
                                                    : 'border-border',
                                            )}
                                        >
                                            <div className="text-sm font-semibold">
                                                All employees
                                            </div>
                                        </RadioItem>
                                        <RadioItem
                                            value="selected"
                                            className={cn(
                                                'rounded-xl border bg-card p-3 text-left outline-none',
                                                !form.data.required_for_all
                                                    ? 'border-primary ring-1 ring-primary'
                                                    : 'border-border',
                                            )}
                                        >
                                            <div className="text-sm font-semibold">
                                                Selected groups
                                            </div>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                Employees must match each
                                                selected category. Within a
                                                category, matching any selected
                                                value is enough.
                                            </p>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                Example: Crew department +
                                                Captain/Chief Engineer rank +
                                                ADNOC/ARAMCO project.
                                            </p>
                                        </RadioItem>
                                    </RadioGroup>
                                    {form.errors.required_for_all ? (
                                        <div className="text-xs text-destructive">
                                            {form.errors.required_for_all}
                                        </div>
                                    ) : null}
                                </div>

                                {!form.data.required_for_all ? (
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
                                ) : null}

                                <div className="space-y-3">
                                    <p className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                        Policy field flags
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        Stored on this company policy only.
                                        These flags do not currently require the
                                        fields on upload or change valid,
                                        missing, expired, or expiring
                                        compliance.
                                    </p>
                                    <label className="flex items-center gap-2 text-sm">
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
                                                    require_document_number:
                                                        next,
                                                });
                                            }}
                                            aria-label="Select all policy field flags"
                                        />
                                        Select all
                                    </label>
                                    <label className="flex items-center gap-2 text-sm">
                                        <Checkbox
                                            checked={
                                                form.data.require_issue_date
                                            }
                                            onCheckedChange={(checked) =>
                                                form.setData(
                                                    'require_issue_date',
                                                    checked === true,
                                                )
                                            }
                                        />
                                        Issue date
                                    </label>
                                    <label className="flex items-center gap-2 text-sm">
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
                                        Expiry date
                                    </label>
                                    <label className="flex items-center gap-2 text-sm">
                                        <Checkbox
                                            checked={
                                                form.data
                                                    .require_document_number
                                            }
                                            onCheckedChange={(checked) =>
                                                form.setData(
                                                    'require_document_number',
                                                    checked === true,
                                                )
                                            }
                                        />
                                        Document number
                                    </label>
                                </div>
                            </>
                        ) : null}
                    </section>

                    <div className="flex items-center justify-end gap-2 pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {current ? 'Save changes' : 'Create'}
                        </Button>
                    </div>
                </form>
            </SheetContent>
        </Sheet>
    );
}
