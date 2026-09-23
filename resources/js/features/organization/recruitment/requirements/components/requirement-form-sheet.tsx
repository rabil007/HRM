import { router, useForm } from '@inertiajs/react';
import {
    AlertCircle,
    Calendar,
    FileUp,
    Loader2,
    Plus,
    Trash2,
    Users,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import RequirementAddHeadcountController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementAddHeadcountController';
import RequirementCheckSimilarController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementCheckSimilarController';
import RequirementController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { toast } from '@/lib/toast';
import type {
    ClientOption,
    PositionOption,
    ProjectOption,
    RequirementDetail,
    UserOption,
} from '@/types/recruitment';
import type { FormPositionLineInput, SimilarRequirementMatch } from '../types';
import { DuplicateDecisionDialog } from './duplicate-decision-dialog';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    initialRequirement?: RequirementDetail | null;
    options: {
        clients: ClientOption[];
        projects: ProjectOption[];
        positions: PositionOption[];
        recruiters: UserOption[];
    };
    onSuccess?: () => void;
};

function csrfToken(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

export function RequirementFormSheet({
    open,
    onOpenChange,
    initialRequirement,
    options,
    onSuccess,
}: Props) {
    const isEditing = Boolean(initialRequirement);
    const today = new Date().toISOString().split('T')[0];

    const defaultPositionLine: FormPositionLineInput = {
        position_id: '',
        required_headcount: 1,
        line_notes: '',
    };

    const { data, setData, processing, errors, reset, clearErrors } = useForm<{
        client_id: string;
        project_id: string;
        client_reference_number: string;
        location: string;
        assigned_to: string;
        request_received_date: string;
        required_by_date: string;
        priority: 'normal' | 'urgent';
        notes: string;
        positions: FormPositionLineInput[];
        attachment: File | null;
        force_create?: boolean;
        _method?: string;
    }>({
        client_id: '',
        project_id: '',
        client_reference_number: '',
        location: '',
        assigned_to: '',
        request_received_date: today,
        required_by_date: '',
        priority: 'normal',
        notes: '',
        positions: [{ ...defaultPositionLine }],
        attachment: null,
        force_create: false,
    });

    const [isCheckingDuplicates, setIsCheckingDuplicates] = useState(false);
    const [duplicateMatches, setDuplicateMatches] = useState<
        SimilarRequirementMatch[]
    >([]);
    const [isDuplicateDialogOpen, setIsDuplicateDialogOpen] = useState(false);

    useEffect(() => {
        if (!open) {
            clearErrors();

            return;
        }

        if (initialRequirement) {
            setData({
                client_id: String(initialRequirement.client_id),
                project_id: initialRequirement.project_id
                    ? String(initialRequirement.project_id)
                    : '',
                client_reference_number:
                    initialRequirement.client_reference_number || '',
                location: initialRequirement.location || '',
                assigned_to: initialRequirement.assigned_to
                    ? String(initialRequirement.assigned_to)
                    : '',
                request_received_date:
                    initialRequirement.request_received_date || today,
                required_by_date: initialRequirement.required_by_date || '',
                priority: initialRequirement.priority || 'normal',
                notes: initialRequirement.notes || '',
                positions:
                    initialRequirement.lines &&
                    initialRequirement.lines.length > 0
                        ? initialRequirement.lines.map((l) => ({
                              id: l.id,
                              position_id: String(l.position_id),
                              required_headcount: l.required_headcount,
                              line_notes: l.line_notes || '',
                          }))
                        : [{ ...defaultPositionLine }],
                attachment: null,
                force_create: false,
            });
        } else {
            reset();
            setData({
                client_id: '',
                project_id: '',
                client_reference_number: '',
                location: '',
                assigned_to: '',
                request_received_date: today,
                required_by_date: '',
                priority: 'normal',
                notes: '',
                positions: [{ ...defaultPositionLine }],
                attachment: null,
                force_create: false,
            });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, initialRequirement]);

    const filteredProjects = useMemo(() => {
        if (!data.client_id) {
            return options.projects;
        }

        return options.projects.filter(
            (p) => String(p.client_id) === String(data.client_id),
        );
    }, [data.client_id, options.projects]);

    const totalHeadcount = useMemo(() => {
        return data.positions.reduce((sum, line) => {
            const count = Number(line.required_headcount) || 0;

            return sum + count;
        }, 0);
    }, [data.positions]);

    const handleAddPositionLine = () => {
        setData('positions', [...data.positions, { ...defaultPositionLine }]);
    };

    const handleRemovePositionLine = (index: number) => {
        if (data.positions.length <= 1) {
            return;
        }

        setData(
            'positions',
            data.positions.filter((_, i) => i !== index),
        );
    };

    const handlePositionChange = (
        index: number,
        field: keyof FormPositionLineInput,
        val: unknown,
    ) => {
        const next = [...data.positions];
        next[index] = {
            ...next[index],
            [field]: val,
        };
        setData('positions', next);
    };

    const submitRequisition = (force = false) => {
        if (isEditing && initialRequirement) {
            router.post(
                RequirementController.update.url(initialRequirement.id),
                {
                    ...data,
                    _method: 'PUT',
                },
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        toast.success('Requirement updated successfully.');
                        onOpenChange(false);
                        onSuccess?.();
                    },
                    onError: () => {
                        toast.error(
                            'Please resolve the errors highlighted below.',
                        );
                    },
                },
            );

            return;
        }

        router.post(
            RequirementController.store.url(),
            {
                ...data,
                force_create: force,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Requirement created successfully.');
                    onOpenChange(false);
                    setIsDuplicateDialogOpen(false);
                    reset();
                    onSuccess?.();
                },
                onError: () => {
                    toast.error('Please resolve the errors highlighted below.');
                },
            },
        );
    };

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();

        // If editing or forcing create, proceed directly
        if (isEditing || data.force_create) {
            submitRequisition(data.force_create);

            return;
        }

        // Check if required basic info is present before calling similarity check
        if (!data.client_id || !data.required_by_date) {
            submitRequisition(false);

            return;
        }

        const validPositions = data.positions.filter((p) =>
            Boolean(p.position_id),
        );

        if (validPositions.length === 0) {
            submitRequisition(false);

            return;
        }

        try {
            setIsCheckingDuplicates(true);
            const response = await fetch(
                RequirementCheckSimilarController.url(),
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        client_id: data.client_id,
                        project_id: data.project_id || null,
                        positions: validPositions.map((p) => ({
                            position_id: p.position_id,
                        })),
                    }),
                },
            );

            if (response.ok) {
                const resData = await response.json();

                if (resData.has_duplicates && resData.duplicates?.length > 0) {
                    setDuplicateMatches(resData.duplicates);
                    setIsDuplicateDialogOpen(true);
                    setIsCheckingDuplicates(false);

                    return;
                }
            }
        } catch {
            // Ignore error and fallback to standard submission
        } finally {
            setIsCheckingDuplicates(false);
        }

        submitRequisition(false);
    };

    const handleAddHeadcountToMatch = (targetRequirementId: number) => {
        router.post(
            RequirementAddHeadcountController.url(targetRequirementId),
            {
                positions: data.positions.map((p) => ({
                    position_id: p.position_id,
                    added_headcount: p.required_headcount,
                    line_notes: p.line_notes || '',
                })),
                reason: data.notes || 'Added headcount from requisition form.',
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Headcount added to existing requirement.');
                    setIsDuplicateDialogOpen(false);
                    onOpenChange(false);
                    reset();
                    onSuccess?.();
                },
                onError: () => {
                    toast.error(
                        'Failed to add headcount to the existing requirement.',
                    );
                },
            },
        );
    };

    return (
        <>
            <Sheet open={open} onOpenChange={onOpenChange}>
                <SheetContent
                    side="right"
                    className="flex w-full flex-col rounded-none glass-card p-0 sm:max-w-3xl"
                >
                    <SheetHeader className="border-b border-border/60 p-6">
                        <SheetTitle className="text-xl font-bold tracking-tight">
                            {isEditing
                                ? `Edit ${initialRequirement?.requirement_number}`
                                : 'Add Recruitment Requirement'}
                        </SheetTitle>
                        <SheetDescription className="mt-1 text-xs text-muted-foreground/80">
                            Capture incoming client requisition, target delivery
                            dates, required positions and headcounts.
                        </SheetDescription>
                    </SheetHeader>

                    <form
                        onSubmit={handleSubmit}
                        className="flex flex-1 flex-col overflow-hidden"
                    >
                        <div className="flex-1 space-y-6 overflow-y-auto p-6">
                            {/* SECTION 1: Client Request */}
                            <div className="space-y-4">
                                <div className="flex items-center gap-2 border-b border-border/40 pb-2">
                                    <span className="flex h-2 w-2 rounded-full bg-primary" />
                                    <h3 className="text-xs font-bold tracking-wider text-foreground uppercase">
                                        1. Client Request
                                    </h3>
                                </div>

                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div className="space-y-1.5">
                                        <Label className="text-xs font-semibold">
                                            Client{' '}
                                            <span className="text-rose-500">
                                                *
                                            </span>
                                        </Label>
                                        <AppSelect
                                            value={
                                                data.client_id
                                                    ? String(data.client_id)
                                                    : 'none'
                                            }
                                            onValueChange={(val) => {
                                                const clientId =
                                                    val === 'none' ? '' : val;
                                                setData((prev) => ({
                                                    ...prev,
                                                    client_id: clientId,
                                                    project_id: '',
                                                }));
                                            }}
                                        >
                                            <AppSelectItem value="none">
                                                Select client...
                                            </AppSelectItem>
                                            {options.clients.map((c) => (
                                                <AppSelectItem
                                                    key={c.id}
                                                    value={String(c.id)}
                                                >
                                                    {c.name}
                                                </AppSelectItem>
                                            ))}
                                        </AppSelect>
                                        {errors.client_id && (
                                            <p className="text-xs text-rose-500">
                                                {errors.client_id}
                                            </p>
                                        )}
                                    </div>

                                    <div className="space-y-1.5">
                                        <Label className="text-xs font-semibold">
                                            Project / Site
                                        </Label>
                                        <AppSelect
                                            value={
                                                data.project_id
                                                    ? String(data.project_id)
                                                    : 'none'
                                            }
                                            onValueChange={(val) =>
                                                setData(
                                                    'project_id',
                                                    val === 'none' ? '' : val,
                                                )
                                            }
                                        >
                                            <AppSelectItem value="none">
                                                None / General
                                            </AppSelectItem>
                                            {filteredProjects.map((p) => (
                                                <AppSelectItem
                                                    key={p.id}
                                                    value={String(p.id)}
                                                >
                                                    {p.title}
                                                </AppSelectItem>
                                            ))}
                                        </AppSelect>
                                        {errors.project_id && (
                                            <p className="text-xs text-rose-500">
                                                {errors.project_id}
                                            </p>
                                        )}
                                    </div>
                                </div>

                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div className="space-y-1.5">
                                        <Label
                                            htmlFor="client_reference_number"
                                            className="text-xs font-semibold"
                                        >
                                            Client Ref / PO #
                                        </Label>
                                        <Input
                                            id="client_reference_number"
                                            placeholder="e.g. PO-98421 or REQ-CLIENT-12"
                                            value={data.client_reference_number}
                                            onChange={(e) =>
                                                setData(
                                                    'client_reference_number',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        {errors.client_reference_number && (
                                            <p className="text-xs text-rose-500">
                                                {errors.client_reference_number}
                                            </p>
                                        )}
                                    </div>

                                    <div className="space-y-1.5">
                                        <Label
                                            htmlFor="location"
                                            className="text-xs font-semibold"
                                        >
                                            Location / Base
                                        </Label>
                                        <Input
                                            id="location"
                                            placeholder="e.g. Dubai Offshore, Abu Dhabi HQ, Ras Laffan"
                                            value={data.location}
                                            onChange={(e) =>
                                                setData(
                                                    'location',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        {errors.location && (
                                            <p className="text-xs text-rose-500">
                                                {errors.location}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            </div>

                            {/* SECTION 2: Deadline & Ownership */}
                            <div className="space-y-4">
                                <div className="flex items-center gap-2 border-b border-border/40 pb-2">
                                    <span className="flex h-2 w-2 rounded-full bg-primary" />
                                    <h3 className="text-xs font-bold tracking-wider text-foreground uppercase">
                                        2. Timeline & Ownership
                                    </h3>
                                </div>

                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div className="space-y-1.5">
                                        <Label
                                            htmlFor="request_received_date"
                                            className="text-xs font-semibold"
                                        >
                                            Request Received Date{' '}
                                            <span className="text-rose-500">
                                                *
                                            </span>
                                        </Label>
                                        <div className="relative">
                                            <Input
                                                id="request_received_date"
                                                type="date"
                                                value={
                                                    data.request_received_date
                                                }
                                                onChange={(e) =>
                                                    setData(
                                                        'request_received_date',
                                                        e.target.value,
                                                    )
                                                }
                                                className="pr-10"
                                                required
                                            />
                                            <Calendar className="pointer-events-none absolute top-1/2 right-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                        </div>
                                        {errors.request_received_date && (
                                            <p className="text-xs text-rose-500">
                                                {errors.request_received_date}
                                            </p>
                                        )}
                                    </div>

                                    <div className="space-y-1.5">
                                        <Label
                                            htmlFor="required_by_date"
                                            className="text-xs font-semibold"
                                        >
                                            Required-By Target Date{' '}
                                            <span className="text-rose-500">
                                                *
                                            </span>
                                        </Label>
                                        <div className="relative">
                                            <Input
                                                id="required_by_date"
                                                type="date"
                                                value={data.required_by_date}
                                                onChange={(e) =>
                                                    setData(
                                                        'required_by_date',
                                                        e.target.value,
                                                    )
                                                }
                                                className="pr-10"
                                                required
                                            />
                                            <Calendar className="pointer-events-none absolute top-1/2 right-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                        </div>
                                        {errors.required_by_date && (
                                            <p className="text-xs text-rose-500">
                                                {errors.required_by_date}
                                            </p>
                                        )}
                                    </div>
                                </div>

                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div className="space-y-1.5">
                                        <Label className="text-xs font-semibold">
                                            Priority
                                        </Label>
                                        <AppSelect
                                            value={data.priority}
                                            onValueChange={(val) =>
                                                setData(
                                                    'priority',
                                                    val as 'normal' | 'urgent',
                                                )
                                            }
                                        >
                                            <AppSelectItem value="normal">
                                                Normal
                                            </AppSelectItem>
                                            <AppSelectItem value="urgent">
                                                Urgent
                                            </AppSelectItem>
                                        </AppSelect>
                                    </div>

                                    <div className="space-y-1.5">
                                        <Label className="text-xs font-semibold">
                                            Assigned Recruiter
                                        </Label>
                                        <AppSelect
                                            value={
                                                data.assigned_to
                                                    ? String(data.assigned_to)
                                                    : 'none'
                                            }
                                            onValueChange={(val) =>
                                                setData(
                                                    'assigned_to',
                                                    val === 'none' ? '' : val,
                                                )
                                            }
                                        >
                                            <AppSelectItem value="none">
                                                Unassigned
                                            </AppSelectItem>
                                            {options.recruiters.map((r) => (
                                                <AppSelectItem
                                                    key={r.id}
                                                    value={String(r.id)}
                                                >
                                                    {r.name}
                                                </AppSelectItem>
                                            ))}
                                        </AppSelect>
                                        {errors.assigned_to && (
                                            <p className="text-xs text-rose-500">
                                                {errors.assigned_to}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            </div>

                            {/* SECTION 3: Positions Required Repeater */}
                            <div className="space-y-4">
                                <div className="flex items-center justify-between border-b border-border/40 pb-2">
                                    <div className="flex items-center gap-2">
                                        <span className="flex h-2 w-2 rounded-full bg-primary" />
                                        <h3 className="text-xs font-bold tracking-wider text-foreground uppercase">
                                            3. Positions Required
                                        </h3>
                                    </div>
                                    <div className="flex items-center gap-2 rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold text-primary">
                                        <Users className="h-3.5 w-3.5" />
                                        <span>
                                            Total Headcount: {totalHeadcount}
                                        </span>
                                    </div>
                                </div>

                                {errors.positions && (
                                    <div className="flex items-center gap-2 rounded-lg border border-rose-500/20 bg-rose-500/[0.05] p-3 text-xs text-rose-600">
                                        <AlertCircle className="h-4 w-4 shrink-0" />
                                        <span>{errors.positions}</span>
                                    </div>
                                )}

                                <div className="space-y-3">
                                    {data.positions.map((line, index) => (
                                        <div
                                            key={index}
                                            className="group relative rounded-xl border border-border/70 bg-muted/20 p-4 transition-all hover:border-border hover:bg-muted/30"
                                        >
                                            <div className="grid grid-cols-12 items-start gap-3">
                                                <div className="col-span-12 space-y-1.5 sm:col-span-6">
                                                    <Label className="text-[11px] font-semibold text-muted-foreground">
                                                        Position Title{' '}
                                                        <span className="text-rose-500">
                                                            *
                                                        </span>
                                                    </Label>
                                                    <AppSelect
                                                        value={
                                                            line.position_id
                                                                ? String(
                                                                      line.position_id,
                                                                  )
                                                                : 'none'
                                                        }
                                                        onValueChange={(val) =>
                                                            handlePositionChange(
                                                                index,
                                                                'position_id',
                                                                val === 'none'
                                                                    ? ''
                                                                    : val,
                                                            )
                                                        }
                                                    >
                                                        <AppSelectItem value="none">
                                                            Choose position...
                                                        </AppSelectItem>
                                                        {options.positions.map(
                                                            (pos) => (
                                                                <AppSelectItem
                                                                    key={pos.id}
                                                                    value={String(
                                                                        pos.id,
                                                                    )}
                                                                >
                                                                    {pos.title}
                                                                    {pos.grade
                                                                        ? ` (${pos.grade})`
                                                                        : ''}
                                                                </AppSelectItem>
                                                            ),
                                                        )}
                                                    </AppSelect>
                                                </div>

                                                <div className="col-span-8 space-y-1.5 sm:col-span-4">
                                                    <Label className="text-[11px] font-semibold text-muted-foreground">
                                                        Required Headcount{' '}
                                                        <span className="text-rose-500">
                                                            *
                                                        </span>
                                                    </Label>
                                                    <Input
                                                        type="number"
                                                        min={1}
                                                        max={500}
                                                        value={
                                                            line.required_headcount
                                                        }
                                                        onChange={(e) =>
                                                            handlePositionChange(
                                                                index,
                                                                'required_headcount',
                                                                parseInt(
                                                                    e.target
                                                                        .value,
                                                                    10,
                                                                ) || 1,
                                                            )
                                                        }
                                                    />
                                                </div>

                                                <div className="col-span-4 flex items-end justify-end pt-5 sm:col-span-2">
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        disabled={
                                                            data.positions
                                                                .length <= 1
                                                        }
                                                        onClick={() =>
                                                            handleRemovePositionLine(
                                                                index,
                                                            )
                                                        }
                                                        className="text-muted-foreground hover:text-rose-500"
                                                        title="Remove position line"
                                                    >
                                                        <Trash2 className="h-4 w-4" />
                                                    </Button>
                                                </div>

                                                <div className="col-span-12 space-y-1.5">
                                                    <Label className="text-[11px] font-semibold text-muted-foreground">
                                                        Position Specific Notes
                                                        / Certifications
                                                        (optional)
                                                    </Label>
                                                    <Input
                                                        placeholder="e.g. Valid BOSIET required, min 3 years offshore experience"
                                                        value={
                                                            line.line_notes ||
                                                            ''
                                                        }
                                                        onChange={(e) =>
                                                            handlePositionChange(
                                                                index,
                                                                'line_notes',
                                                                e.target.value,
                                                            )
                                                        }
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    ))}

                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={handleAddPositionLine}
                                        className="w-full gap-2 border-dashed border-primary/30 text-primary hover:border-primary hover:bg-primary/5"
                                    >
                                        <Plus className="h-4 w-4" />
                                        Add Another Position
                                    </Button>
                                </div>
                            </div>

                            {/* SECTION 4: Notes & Attachments */}
                            <div className="space-y-4">
                                <div className="flex items-center gap-2 border-b border-border/40 pb-2">
                                    <span className="flex h-2 w-2 rounded-full bg-primary" />
                                    <h3 className="text-xs font-bold tracking-wider text-foreground uppercase">
                                        4. Notes & Attachments
                                    </h3>
                                </div>

                                <div className="space-y-1.5">
                                    <Label
                                        htmlFor="notes"
                                        className="text-xs font-semibold"
                                    >
                                        Requirement Notes / Scope of Work
                                    </Label>
                                    <Textarea
                                        id="notes"
                                        rows={3}
                                        placeholder="Add background context, mobilization terms, or candidate requirements..."
                                        value={data.notes}
                                        onChange={(e) =>
                                            setData('notes', e.target.value)
                                        }
                                    />
                                    {errors.notes && (
                                        <p className="text-xs text-rose-500">
                                            {errors.notes}
                                        </p>
                                    )}
                                </div>

                                <div className="space-y-1.5">
                                    <Label
                                        htmlFor="attachment"
                                        className="text-xs font-semibold"
                                    >
                                        Attach Client Email / Specification
                                        Document
                                    </Label>
                                    <div className="flex items-center gap-3">
                                        <label
                                            htmlFor="attachment"
                                            className="flex cursor-pointer items-center gap-2 rounded-lg border border-border/80 bg-muted/30 px-4 py-2.5 text-xs font-medium text-foreground transition-colors hover:bg-muted/50"
                                        >
                                            <FileUp className="h-4 w-4 text-primary" />
                                            <span>
                                                {data.attachment
                                                    ? data.attachment.name
                                                    : 'Select file (PDF, Word, Excel, Images up to 10MB)'}
                                            </span>
                                        </label>
                                        <input
                                            id="attachment"
                                            type="file"
                                            className="hidden"
                                            accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.png,.jpg,.jpeg"
                                            onChange={(e) => {
                                                const file =
                                                    e.target.files?.[0] || null;
                                                setData('attachment', file);
                                            }}
                                        />
                                        {data.attachment && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    setData('attachment', null)
                                                }
                                                className="text-xs text-rose-500 hover:text-rose-600"
                                            >
                                                Remove
                                            </Button>
                                        )}
                                    </div>
                                    {errors.attachment && (
                                        <p className="text-xs text-rose-500">
                                            {errors.attachment}
                                        </p>
                                    )}
                                </div>
                            </div>
                        </div>

                        <SheetFooter className="flex flex-row items-center justify-between gap-3 border-t border-border/60 p-6">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => onOpenChange(false)}
                                disabled={processing || isCheckingDuplicates}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={processing || isCheckingDuplicates}
                                className="gap-2"
                            >
                                {(processing || isCheckingDuplicates) && (
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                )}
                                {isEditing
                                    ? 'Save Changes'
                                    : 'Submit Requisition'}
                            </Button>
                        </SheetFooter>
                    </form>
                </SheetContent>
            </Sheet>

            {/* Duplicate Decision Dialog */}
            <DuplicateDecisionDialog
                open={isDuplicateDialogOpen}
                onOpenChange={setIsDuplicateDialogOpen}
                matches={duplicateMatches}
                onAddHeadcount={handleAddHeadcountToMatch}
                onCreateSeparateBatch={() => {
                    setIsDuplicateDialogOpen(false);
                    submitRequisition(true);
                }}
                onReturnAndReview={() => {
                    setIsDuplicateDialogOpen(false);
                }}
                isSubmitting={processing}
            />
        </>
    );
}
