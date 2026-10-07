import { router, useForm, usePage } from '@inertiajs/react';
import {
    AlertCircle,
    Calendar,
    CheckCircle2,
    FileUp,
    Loader2,
    Plus,
    RotateCcw,
    Trash2,
    Users,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import RequirementAddHeadcountController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementAddHeadcountController';
import RequirementCheckSimilarController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementCheckSimilarController';
import RequirementController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { CreatableSelect } from '@/components/ui/creatable-select';
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
import { useCreatableMasterData } from '@/hooks/use-creatable-master-data';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import type { Auth } from '@/types/auth';
import type {
    ClientOption,
    PositionOption,
    ProjectOption,
    RequirementDetail,
    UserOption,
} from '@/types/recruitment';
import {
    createRequirementFormSnapshot,
    dedupeNotificationRecipientIds,
    firstInvalidRequirementField,
    isRequirementFormDirty,
    requirementFieldError,
    requirementFormFieldSelector,
    resolveDuplicateDialogSubmitIntent,
} from '../lib/requirement-form';
import type { RequirementFormSnapshot } from '../lib/requirement-form';
import {
    appendRequirementClientOption,
    appendRequirementProjectOption,
    selectableRequirementClients,
    selectableRequirementProjectsForClient,
    formatRequirementProjectCreateLabel,
    resolveRequirementProjectAfterClientChange,
    syncRequirementClientOptions,
    syncRequirementProjectOptions,
} from '../lib/requirement-form-client-project';
import {
    canRemoveRequirementPositionLine,
    hydrateNewRequirementForm,
    hydrateRequirementFormFromDetail,
    isRequirementPreparationStatus,
    shouldShowRequirementPositionStructureLockWarning,
} from '../lib/requirement-form-hydration';
import {
    isSalaryAtPositionDefault,
    isSalaryEditedFromPosition,
    resolveDefaultSalaryForPosition,
} from '../lib/requirement-salary';
import {
    canShowRequirementSubmitFormAction,
    compactSubmissionAttentionLabel,
    evaluateRequirementFormSubmissionReadiness,
    readinessToFormFieldErrors,
} from '../lib/requirement-submission-readiness';
import type { FormPositionLineInput, SimilarRequirementMatch } from '../types';
import { DuplicateDecisionDialog } from './duplicate-decision-dialog';
import { RequirementNotificationRecipientsMultiSelect } from './requirement-notification-recipients-multi-select';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    initialRequirement?: RequirementDetail | null;
    canSubmit?: boolean;
    options: {
        clients: ClientOption[];
        projects: ProjectOption[];
        positions: PositionOption[];
        recruiters: UserOption[];
        notification_users?: UserOption[];
        currency_code?: string;
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
    canSubmit = false,
    options,
    onSuccess,
}: Props) {
    const { auth } = usePage().props as { auth: Auth };
    const currentUserId = auth.user?.id ?? null;
    const showSubmitAction = canShowRequirementSubmitFormAction(canSubmit);
    const isEditing = Boolean(initialRequirement);
    const today = new Date().toISOString().split('T')[0];

    const defaultPositionLine: FormPositionLineInput = {
        position_id: '',
        required_headcount: 1,
        salary_min: '',
        salary_max: '',
        salary_currency_code: options.currency_code || 'AED',
        line_notes: '',
    };

    const { data, setData, errors, setError, reset, clearErrors } = useForm<{
        client_id: string;
        project_id: string;
        location: string;
        assigned_to: string;
        notification_recipient_ids: number[];
        request_received_date: string;
        required_by_date: string;
        priority: 'normal' | 'urgent';
        notes: string;
        positions: FormPositionLineInput[];
        attachment: File | null;
        force_create?: boolean;
        submit_for_approval?: boolean;
        _method?: string;
    }>({
        client_id: '',
        project_id: '',
        location: '',
        assigned_to: '',
        notification_recipient_ids: [],
        request_received_date: today,
        required_by_date: '',
        priority: 'normal',
        notes: '',
        positions: [{ ...defaultPositionLine }],
        attachment: null,
        force_create: false,
        submit_for_approval: false,
    });

    const [isCheckingDuplicates, setIsCheckingDuplicates] = useState(false);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [duplicateMatches, setDuplicateMatches] = useState<
        SimilarRequirementMatch[]
    >([]);
    const [isDuplicateDialogOpen, setIsDuplicateDialogOpen] = useState(false);
    const [baseline, setBaseline] = useState<RequirementFormSnapshot | null>(
        null,
    );
    const [confirmDiscardOpen, setConfirmDiscardOpen] = useState(false);
    const [clientItems, setClientItems] = useState<ClientOption[]>(() =>
        syncRequirementClientOptions(options.clients),
    );
    const [projectItems, setProjectItems] = useState<ProjectOption[]>(() =>
        syncRequirementProjectOptions(options.projects),
    );
    const pendingCloseRef = useRef(false);
    const pendingSubmitForApprovalRef = useRef(false);
    const formBodyRef = useRef<HTMLDivElement | null>(null);

    const isReturnedEdit =
        isEditing && initialRequirement?.status === 'returned';
    const isPreparationEditable =
        Boolean(initialRequirement?.can_edit) &&
        isRequirementPreparationStatus(initialRequirement?.status);
    const canEditPositionStructure = !isEditing || isPreparationEditable;
    const draftSaveLabel = isReturnedEdit ? 'Save Changes' : 'Save Draft';
    const submitSaveLabel = isReturnedEdit
        ? 'Save & Resubmit'
        : isEditing
          ? 'Save & Submit for Approval'
          : 'Create & Submit for Approval';

    const notificationUserOptions = useMemo(
        () => options.notification_users ?? options.recruiters,
        [options.notification_users, options.recruiters],
    );

    const positionTitleLookup = useMemo(() => {
        const lookup: Record<string, string> = {};

        for (const position of options.positions) {
            lookup[String(position.id)] = position.title;
        }

        return lookup;
    }, [options.positions]);

    const invalidControlClass = (
        field: string,
        index?: number,
        attribute?: string,
    ) => {
        return requirementFieldError(
            errors as Record<string, string | string[] | undefined>,
            field,
            index,
            attribute,
        )
            ? 'border-destructive ring-1 ring-destructive/40'
            : '';
    };

    const submissionReadiness = useMemo(
        () =>
            evaluateRequirementFormSubmissionReadiness({
                clientId: data.client_id,
                requestReceivedDate: data.request_received_date,
                requiredByDate: data.required_by_date,
                assignedTo: data.assigned_to,
                positions: data.positions,
                positionTitles: positionTitleLookup,
                creatorUserId: currentUserId,
            }),
        [
            currentUserId,
            data.assigned_to,
            data.client_id,
            data.positions,
            data.request_received_date,
            data.required_by_date,
            positionTitleLookup,
        ],
    );

    const { canCreate: canCreateClient, createConfig: clientCreateConfigBase } =
        useCreatableMasterData('client');
    const {
        canCreate: canCreateProject,
        createConfig: projectCreateConfigBase,
    } = useCreatableMasterData('project', {
        clientId: data.client_id || null,
    });

    const selectedClientName = useMemo(() => {
        if (!data.client_id) {
            return null;
        }

        return (
            clientItems.find(
                (client) => String(client.id) === String(data.client_id),
            )?.name ?? null
        );
    }, [clientItems, data.client_id]);

    const clientCreateConfig = useMemo(
        () => ({
            submit: async (query: string) => {
                const created = await clientCreateConfigBase.submit(query);

                setClientItems((previous) =>
                    appendRequirementClientOption(previous, created),
                );

                return created;
            },
        }),
        [clientCreateConfigBase],
    );

    const projectCreateConfig = useMemo(
        () => ({
            submit: async (query: string) => {
                const created = await projectCreateConfigBase.submit(query);

                setProjectItems((previous) =>
                    appendRequirementProjectOption(
                        previous,
                        created,
                        data.client_id,
                    ),
                );

                return created;
            },
        }),
        [data.client_id, projectCreateConfigBase],
    );

    const clientSelectOptions = useMemo(
        () =>
            selectableRequirementClients(clientItems, data.client_id).map(
                (client) => ({
                    id: client.id,
                    label: client.name,
                    value: String(client.id),
                }),
            ),
        [clientItems, data.client_id],
    );

    const projectSelectOptions = useMemo(
        () =>
            selectableRequirementProjectsForClient(
                projectItems,
                data.client_id,
                data.project_id,
            ).map((project) => ({
                id: project.id,
                label: project.title,
                value: String(project.id),
            })),
        [data.client_id, data.project_id, projectItems],
    );

    const currentSnapshot = useMemo(
        () =>
            createRequirementFormSnapshot({
                client_id: data.client_id,
                project_id: data.project_id,
                location: data.location,
                assigned_to: data.assigned_to,
                notification_recipient_ids: data.notification_recipient_ids,
                request_received_date: data.request_received_date,
                required_by_date: data.required_by_date,
                priority: data.priority,
                notes: data.notes,
                positions: data.positions,
                attachment: data.attachment,
            }),
        [data],
    );

    const isDirty = isRequirementFormDirty(baseline, currentSnapshot);
    const busy = isSubmitting || isCheckingDuplicates;

    useEffect(() => {
        if (!open) {
            clearErrors();
            setBaseline(null);
            setConfirmDiscardOpen(false);
            pendingCloseRef.current = false;
            setIsSubmitting(false);

            return;
        }

        setClientItems(syncRequirementClientOptions(options.clients));
        setProjectItems(syncRequirementProjectOptions(options.projects));

        const nextData = initialRequirement
            ? {
                  ...hydrateRequirementFormFromDetail({
                      requirement: initialRequirement,
                      today,
                      currencyCode: options.currency_code || 'AED',
                      defaultPositionLine,
                  }),
                  attachment: null as File | null,
                  force_create: false,
              }
            : {
                  ...hydrateNewRequirementForm({
                      today,
                      defaultPositionLine,
                  }),
                  attachment: null as File | null,
                  force_create: false,
              };

        if (!initialRequirement) {
            reset();
        }

        setData(nextData);
        setBaseline(
            createRequirementFormSnapshot({
                ...nextData,
                attachment: null,
            }),
        );
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, initialRequirement]);

    const clientOptionsKey = (options.clients ?? [])
        .map((client) => `${client.id}:${client.name}`)
        .join('|');
    const projectOptionsKey = (options.projects ?? [])
        .map(
            (project) =>
                `${project.id}:${project.title}:${(project.client_ids ?? []).join(',')}`,
        )
        .join('|');

    useEffect(() => {
        if (!open) {
            return;
        }

        setClientItems(syncRequirementClientOptions(options.clients));
        setProjectItems(syncRequirementProjectOptions(options.projects));
        // eslint-disable-next-line react-hooks/exhaustive-deps -- sync when option ids/labels change, not array reference
    }, [open, clientOptionsKey, projectOptionsKey]);

    useEffect(() => {
        if (!open || !isDirty) {
            return;
        }

        const handler = (event: BeforeUnloadEvent) => {
            event.preventDefault();
        };

        window.addEventListener('beforeunload', handler);

        return () => window.removeEventListener('beforeunload', handler);
    }, [open, isDirty]);

    const focusFirstInvalidField = (
        nextErrors: Record<string, string | undefined>,
    ) => {
        const field = firstInvalidRequirementField(nextErrors);

        if (!field || !formBodyRef.current) {
            return;
        }

        const selector = requirementFormFieldSelector(field);
        const target = formBodyRef.current.querySelector<HTMLElement>(selector);

        if (!target) {
            return;
        }

        const focusable = target.matches(
            'input, select, textarea, button, [tabindex]:not([tabindex="-1"]), [role="combobox"]',
        )
            ? target
            : target.querySelector<HTMLElement>(
                  'input, select, textarea, button, [tabindex]:not([tabindex="-1"]), [role="combobox"]',
              );

        focusable?.focus();
        target.scrollIntoView({
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)')
                .matches
                ? 'auto'
                : 'smooth',
            block: 'center',
        });
    };

    const requestClose = () => {
        if (busy) {
            return;
        }

        if (isDirty) {
            pendingCloseRef.current = true;
            setConfirmDiscardOpen(true);

            return;
        }

        onOpenChange(false);
    };

    const confirmDiscard = () => {
        setConfirmDiscardOpen(false);
        pendingCloseRef.current = false;
        onOpenChange(false);
    };

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
        if (
            !canRemoveRequirementPositionLine({
                canEditPositionStructure,
                isPreparationEditable,
                positionCount: data.positions.length,
            })
        ) {
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

    const handleSelectPosition = (index: number, positionIdVal: string) => {
        const next = [...data.positions];
        const selectedPos = options.positions.find(
            (p) => String(p.id) === String(positionIdVal),
        );
        const defaults = resolveDefaultSalaryForPosition(selectedPos);

        next[index] = {
            ...next[index],
            position_id: positionIdVal === 'none' ? '' : positionIdVal,
            salary_min:
                defaults.salary_min !== null ? String(defaults.salary_min) : '',
            salary_max:
                defaults.salary_max !== null ? String(defaults.salary_max) : '',
            salary_currency_code: options.currency_code || 'AED',
        };
        setData('positions', next);
    };

    const handleResetPositionSalary = (index: number) => {
        const line = data.positions[index];
        const selectedPos = options.positions.find(
            (p) => String(p.id) === String(line.position_id),
        );
        const defaults = resolveDefaultSalaryForPosition(selectedPos);

        const next = [...data.positions];
        next[index] = {
            ...next[index],
            salary_min:
                defaults.salary_min !== null ? String(defaults.salary_min) : '',
            salary_max:
                defaults.salary_max !== null ? String(defaults.salary_max) : '',
            salary_currency_code: options.currency_code || 'AED',
        };
        setData('positions', next);
    };

    const normalizedNotificationRecipientIds = useMemo(
        () =>
            dedupeNotificationRecipientIds(
                data.notification_recipient_ids.filter((id) => {
                    const assignedId = data.assigned_to
                        ? Number(data.assigned_to)
                        : null;

                    return assignedId === null || id !== assignedId;
                }),
            ),
        [data.assigned_to, data.notification_recipient_ids],
    );

    const submitRequisition = (force = false, submitForApproval = false) => {
        if (isSubmitting) {
            return;
        }

        setIsSubmitting(true);

        if (isEditing && initialRequirement) {
            router.post(
                RequirementController.update.url(initialRequirement.id),
                {
                    client_id: data.client_id || null,
                    project_id: data.project_id || null,
                    location: data.location || null,
                    assigned_to: data.assigned_to || null,
                    notification_recipient_ids:
                        normalizedNotificationRecipientIds,
                    request_received_date: data.request_received_date || null,
                    required_by_date: data.required_by_date || null,
                    priority: data.priority,
                    notes: data.notes || null,
                    positions: data.positions,
                    attachment: data.attachment,
                    submit_for_approval: submitForApproval,
                    _method: 'PUT',
                },
                {
                    preserveScroll: true,
                    forceFormData: true,
                    onSuccess: () => {
                        toast.success(
                            submitForApproval
                                ? isReturnedEdit
                                    ? 'Requirement saved and resubmitted for approval.'
                                    : 'Requirement saved and submitted for approval.'
                                : 'Requirement updated successfully.',
                        );
                        setBaseline(currentSnapshot);
                        onOpenChange(false);
                        onSuccess?.();
                    },
                    onError: (errs) => {
                        toast.error(
                            errs.submission_readiness ||
                                errs.assigned_to ||
                                errs.request_received_date ||
                                'Please resolve the errors highlighted below.',
                        );
                        focusFirstInvalidField(errs);
                    },
                    onFinish: () => setIsSubmitting(false),
                },
            );

            return;
        }

        router.post(
            RequirementController.store.url(),
            {
                client_id: data.client_id || null,
                project_id: data.project_id || null,
                location: data.location || null,
                assigned_to: data.assigned_to || null,
                notification_recipient_ids: normalizedNotificationRecipientIds,
                request_received_date: data.request_received_date || null,
                required_by_date: data.required_by_date || null,
                priority: data.priority,
                notes: data.notes || null,
                positions: data.positions,
                attachment: data.attachment,
                submit_for_approval: submitForApproval,
                ignore_duplicate_warning: force,
                force_create: force,
            },
            {
                preserveScroll: true,
                forceFormData: true,
                onSuccess: () => {
                    toast.success(
                        submitForApproval
                            ? 'Requirement saved and submitted for approval.'
                            : 'Requirement saved as draft.',
                    );
                    onOpenChange(false);
                    setIsDuplicateDialogOpen(false);
                    reset();
                    onSuccess?.();
                },
                onError: (errs) => {
                    if (errs.duplicate) {
                        toast.error(errs.duplicate);
                    } else {
                        toast.error(
                            'Please resolve the errors highlighted below.',
                        );
                    }

                    focusFirstInvalidField(errs);
                },
                onFinish: () => setIsSubmitting(false),
            },
        );
    };

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();

        if (busy) {
            return;
        }

        const submitForApproval = pendingSubmitForApprovalRef.current;

        // If editing or forcing create, proceed directly
        if (isEditing || data.force_create) {
            pendingSubmitForApprovalRef.current = false;
            submitRequisition(data.force_create, submitForApproval);

            return;
        }

        // Check if required basic info is present before calling similarity check
        if (!data.client_id || !data.required_by_date) {
            pendingSubmitForApprovalRef.current = false;
            submitRequisition(false, submitForApproval);

            return;
        }

        const validPositions = data.positions.filter((p) =>
            Boolean(p.position_id),
        );

        if (validPositions.length === 0) {
            pendingSubmitForApprovalRef.current = false;
            submitRequisition(false, submitForApproval);

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
                    // Keep pendingSubmitForApprovalRef until the duplicate dialog resolves.
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

        pendingSubmitForApprovalRef.current = false;
        submitRequisition(false, submitForApproval);
    };

    const queueSubmit = (
        event: React.MouseEvent<HTMLButtonElement>,
        submitForApproval: boolean,
    ) => {
        if (submitForApproval && !submissionReadiness.ready) {
            event.preventDefault();
            clearErrors();

            const fieldErrors = readinessToFormFieldErrors(
                submissionReadiness,
                data.positions,
            );

            setError(fieldErrors as Record<string, string>);

            toast.error(
                compactSubmissionAttentionLabel(
                    submissionReadiness.remaining_count,
                ),
            );
            focusFirstInvalidField(fieldErrors);

            return;
        }

        clearErrors();
        pendingSubmitForApprovalRef.current = submitForApproval;
    };

    const handleAddHeadcountToMatch = (
        targetRequirementId: number,
        reason: string,
    ) => {
        pendingSubmitForApprovalRef.current = false;
        router.post(
            RequirementAddHeadcountController.url(targetRequirementId),
            {
                positions: data.positions.map((p) => ({
                    position_id: p.position_id,
                    added_headcount: p.required_headcount,
                    line_notes: p.line_notes || '',
                })),
                reason:
                    reason ||
                    data.notes ||
                    'Consolidated headcount from duplicate creation.',
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
            <Sheet
                open={open}
                onOpenChange={(nextOpen) => {
                    if (!nextOpen) {
                        requestClose();

                        return;
                    }

                    onOpenChange(true);
                }}
            >
                <SheetContent
                    side="right"
                    className="flex w-full flex-col rounded-none glass-card p-0 sm:max-w-3xl"
                    onInteractOutside={(event) => {
                        if (isDirty || busy) {
                            event.preventDefault();
                            requestClose();
                        }
                    }}
                    onEscapeKeyDown={(event) => {
                        if (isDirty || busy) {
                            event.preventDefault();
                            requestClose();
                        }
                    }}
                >
                    <SheetHeader className="border-b border-border/60 p-6">
                        <SheetTitle className="text-xl font-bold tracking-tight">
                            {isEditing
                                ? `Edit ${initialRequirement?.requirement_number}`
                                : 'Create requirement'}
                        </SheetTitle>
                        <SheetDescription className="mt-1 text-xs text-muted-foreground/80">
                            Capture the client request, required roles,
                            headcount, ownership, and target delivery date.
                            Server validation remains the source of truth.
                        </SheetDescription>
                    </SheetHeader>

                    <form
                        onSubmit={handleSubmit}
                        className="flex flex-1 flex-col overflow-hidden"
                    >
                        <div
                            ref={formBodyRef}
                            className="flex-1 space-y-6 overflow-y-auto p-6"
                        >
                            {/* SECTION 1: Client Request */}
                            <div className="space-y-4">
                                <div className="flex items-center gap-2 border-b border-border/40 pb-2">
                                    <span className="flex h-2 w-2 rounded-full bg-primary" />
                                    <h3 className="text-xs font-bold tracking-wider text-foreground uppercase">
                                        1. Client Request
                                    </h3>
                                </div>

                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div
                                        className="space-y-1.5"
                                        data-requirement-field="client_id"
                                    >
                                        <Label className="text-xs font-semibold">
                                            Client
                                        </Label>
                                        <CreatableSelect
                                            className={invalidControlClass(
                                                'client_id',
                                            )}
                                            value={
                                                data.client_id
                                                    ? String(data.client_id)
                                                    : ''
                                            }
                                            onValueChange={(val) => {
                                                const clientId = val;

                                                setData((prev) => ({
                                                    ...prev,
                                                    client_id: clientId,
                                                    project_id:
                                                        resolveRequirementProjectAfterClientChange(
                                                            prev.project_id,
                                                            clientId,
                                                            projectItems,
                                                        ),
                                                }));
                                            }}
                                            options={clientSelectOptions}
                                            onOptionsChange={(nextOptions) => {
                                                setClientItems((previous) => {
                                                    let next = [...previous];

                                                    for (const option of nextOptions) {
                                                        next =
                                                            appendRequirementClientOption(
                                                                next,
                                                                {
                                                                    id: option.id,
                                                                    label: option.label,
                                                                },
                                                            );
                                                    }

                                                    return next;
                                                });
                                            }}
                                            placeholder="Select client..."
                                            searchPlaceholder="Search clients..."
                                            creatable
                                            canCreate={canCreateClient}
                                            createConfig={clientCreateConfig}
                                            emptyMessage="No matching clients."
                                        />
                                        <p className="text-[11px] text-muted-foreground">
                                            Choose the client requesting these
                                            roles. Projects are filtered to this
                                            client.
                                        </p>
                                        {errors.client_id && (
                                            <p className="text-xs text-rose-500">
                                                {errors.client_id}
                                            </p>
                                        )}
                                    </div>

                                    <div
                                        className="space-y-1.5"
                                        data-requirement-field="project_id"
                                    >
                                        <Label className="text-xs font-semibold">
                                            Project / Site
                                        </Label>
                                        <CreatableSelect
                                            className={invalidControlClass(
                                                'project_id',
                                            )}
                                            value={
                                                data.project_id
                                                    ? String(data.project_id)
                                                    : ''
                                            }
                                            onValueChange={(val) =>
                                                setData('project_id', val)
                                            }
                                            options={projectSelectOptions}
                                            onOptionsChange={(nextOptions) => {
                                                setProjectItems((previous) => {
                                                    let next = [...previous];

                                                    for (const option of nextOptions) {
                                                        next =
                                                            appendRequirementProjectOption(
                                                                next,
                                                                {
                                                                    id: option.id,
                                                                    label: option.label,
                                                                },
                                                                data.client_id,
                                                            );
                                                    }

                                                    return next;
                                                });
                                            }}
                                            placeholder={
                                                data.client_id
                                                    ? 'None / General'
                                                    : 'Select a client first'
                                            }
                                            searchPlaceholder="Search projects..."
                                            disabled={!data.client_id}
                                            creatable={Boolean(data.client_id)}
                                            canCreate={
                                                Boolean(data.client_id) &&
                                                canCreateProject
                                            }
                                            createConfig={
                                                data.client_id
                                                    ? projectCreateConfig
                                                    : undefined
                                            }
                                            createLabel={(query) =>
                                                formatRequirementProjectCreateLabel(
                                                    query,
                                                    selectedClientName,
                                                )
                                            }
                                            emptyMessage="No matching projects for this client."
                                        />
                                        {errors.project_id && (
                                            <p className="text-xs text-rose-500">
                                                {errors.project_id}
                                            </p>
                                        )}
                                    </div>
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
                                            setData('location', e.target.value)
                                        }
                                    />
                                    {errors.location && (
                                        <p className="text-xs text-rose-500">
                                            {errors.location}
                                        </p>
                                    )}
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
                                            Request Received from Client
                                        </Label>
                                        <div className="relative">
                                            <Input
                                                id="request_received_date"
                                                type="date"
                                                value={
                                                    data.request_received_date
                                                }
                                                max={
                                                    data.required_by_date ||
                                                    undefined
                                                }
                                                onChange={(e) =>
                                                    setData(
                                                        'request_received_date',
                                                        e.target.value,
                                                    )
                                                }
                                                aria-invalid={Boolean(
                                                    errors.request_received_date,
                                                )}
                                                className={cn(
                                                    'pr-10',
                                                    invalidControlClass(
                                                        'request_received_date',
                                                    ),
                                                )}
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
                                            Required-By Target Date
                                        </Label>
                                        <div className="relative">
                                            <Input
                                                id="required_by_date"
                                                type="date"
                                                value={data.required_by_date}
                                                disabled={
                                                    isEditing &&
                                                    !isReturnedEdit &&
                                                    initialRequirement?.status !==
                                                        'draft'
                                                }
                                                onChange={(e) =>
                                                    setData(
                                                        'required_by_date',
                                                        e.target.value,
                                                    )
                                                }
                                                aria-invalid={Boolean(
                                                    errors.required_by_date,
                                                )}
                                                className={cn(
                                                    'pr-10',
                                                    invalidControlClass(
                                                        'required_by_date',
                                                    ),
                                                )}
                                            />
                                            <Calendar className="pointer-events-none absolute top-1/2 right-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                        </div>
                                        {isEditing &&
                                            !isReturnedEdit &&
                                            initialRequirement?.status !==
                                                'draft' && (
                                                <p className="text-[11px] text-muted-foreground">
                                                    Deadline is locked. Use{' '}
                                                    <strong>
                                                        Extend Deadline
                                                    </strong>{' '}
                                                    from requirement actions to
                                                    update it with an audit
                                                    reason.
                                                </p>
                                            )}
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

                                    <div
                                        className="space-y-1.5"
                                        data-requirement-field="assigned_to"
                                    >
                                        <Label className="text-xs font-semibold">
                                            Assigned Recruiter
                                        </Label>
                                        <AppSelect
                                            value={
                                                data.assigned_to
                                                    ? String(data.assigned_to)
                                                    : 'none'
                                            }
                                            invalid={Boolean(
                                                errors.assigned_to,
                                            )}
                                            className={invalidControlClass(
                                                'assigned_to',
                                            )}
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

                                <RequirementNotificationRecipientsMultiSelect
                                    options={notificationUserOptions}
                                    assignedTo={data.assigned_to}
                                    value={data.notification_recipient_ids}
                                    onChange={(ids) =>
                                        setData(
                                            'notification_recipient_ids',
                                            ids,
                                        )
                                    }
                                    error={
                                        typeof errors.notification_recipient_ids ===
                                        'string'
                                            ? errors.notification_recipient_ids
                                            : undefined
                                    }
                                />
                            </div>

                            {/* SECTION 3: Positions Required Repeater */}
                            <div
                                className="space-y-4"
                                data-requirement-field="positions"
                            >
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

                                {shouldShowRequirementPositionStructureLockWarning(
                                    canEditPositionStructure,
                                ) && (
                                    <div className="rounded-lg border border-amber-500/20 bg-amber-500/5 p-3 text-xs text-amber-700 dark:text-amber-400">
                                        Position titles and headcounts are
                                        locked in generic edit. Salary ranges
                                        remain editable. To adjust headcounts,
                                        use <strong>Change Headcount</strong> on
                                        the requirement details page.
                                    </div>
                                )}

                                {errors.positions && (
                                    <div className="flex items-center gap-2 rounded-lg border border-rose-500/20 bg-rose-500/[0.05] p-3 text-xs text-rose-600">
                                        <AlertCircle className="h-4 w-4 shrink-0" />
                                        <span>{errors.positions}</span>
                                    </div>
                                )}

                                <div className="space-y-3">
                                    {data.positions.length === 0 &&
                                        isPreparationEditable && (
                                            <p className="rounded-lg border border-dashed border-border/70 bg-muted/20 p-3 text-xs text-muted-foreground">
                                                No positions yet. Add a position
                                                before submitting for approval.
                                            </p>
                                        )}
                                    {data.positions.map((line, index) => {
                                        const selectedPos =
                                            options.positions.find(
                                                (p) =>
                                                    String(p.id) ===
                                                    String(line.position_id),
                                            );
                                        const posHasSalary = Boolean(
                                            selectedPos &&
                                            (selectedPos.min_salary !== null ||
                                                selectedPos.max_salary !==
                                                    null),
                                        );
                                        const isAtDefault = Boolean(
                                            selectedPos &&
                                            isSalaryAtPositionDefault(
                                                line,
                                                selectedPos,
                                            ),
                                        );
                                        const isCopiedFromPosition = Boolean(
                                            posHasSalary &&
                                            isAtDefault &&
                                            (line.salary_min ||
                                                line.salary_max),
                                        );
                                        const isEditedFromPosition = Boolean(
                                            selectedPos &&
                                            isSalaryEditedFromPosition(
                                                line,
                                                selectedPos,
                                            ),
                                        );
                                        const canResetToDefault = Boolean(
                                            posHasSalary &&
                                            isEditedFromPosition,
                                        );
                                        const anyErrors = errors as Record<
                                            string,
                                            string | undefined
                                        >;
                                        const positionIdError =
                                            requirementFieldError(
                                                anyErrors,
                                                '',
                                                index,
                                                'position_id',
                                            );
                                        const headcountError =
                                            requirementFieldError(
                                                anyErrors,
                                                '',
                                                index,
                                                'required_headcount',
                                            );
                                        const minSalaryError =
                                            requirementFieldError(
                                                anyErrors,
                                                '',
                                                index,
                                                'salary_min',
                                            );
                                        const maxSalaryError =
                                            requirementFieldError(
                                                anyErrors,
                                                '',
                                                index,
                                                'salary_max',
                                            );

                                        return (
                                            <div
                                                key={index}
                                                className="group relative rounded-xl border border-border/70 bg-muted/20 p-4 transition-all hover:border-border hover:bg-muted/30"
                                            >
                                                <div className="grid grid-cols-12 items-start gap-3">
                                                    <div
                                                        className="col-span-12 space-y-1.5 sm:col-span-6"
                                                        data-requirement-field={`positions.${index}.position_id`}
                                                    >
                                                        <Label className="text-[11px] font-semibold text-muted-foreground">
                                                            Position Title
                                                        </Label>
                                                        <AppSelect
                                                            value={
                                                                line.position_id
                                                                    ? String(
                                                                          line.position_id,
                                                                      )
                                                                    : 'none'
                                                            }
                                                            invalid={Boolean(
                                                                positionIdError,
                                                            )}
                                                            className={invalidControlClass(
                                                                '',
                                                                index,
                                                                'position_id',
                                                            )}
                                                            disabled={
                                                                !canEditPositionStructure
                                                            }
                                                            onValueChange={(
                                                                val,
                                                            ) =>
                                                                handleSelectPosition(
                                                                    index,
                                                                    val,
                                                                )
                                                            }
                                                        >
                                                            <AppSelectItem value="none">
                                                                Choose
                                                                position...
                                                            </AppSelectItem>
                                                            {options.positions.map(
                                                                (pos) => (
                                                                    <AppSelectItem
                                                                        key={
                                                                            pos.id
                                                                        }
                                                                        value={String(
                                                                            pos.id,
                                                                        )}
                                                                    >
                                                                        {
                                                                            pos.title
                                                                        }
                                                                        {pos.grade
                                                                            ? ` (${pos.grade})`
                                                                            : ''}
                                                                    </AppSelectItem>
                                                                ),
                                                            )}
                                                        </AppSelect>
                                                        {positionIdError && (
                                                            <p className="text-[11px] text-destructive">
                                                                {
                                                                    positionIdError
                                                                }
                                                            </p>
                                                        )}
                                                    </div>

                                                    <div
                                                        className="col-span-8 space-y-1.5 sm:col-span-4"
                                                        data-requirement-field={`positions.${index}.required_headcount`}
                                                    >
                                                        <Label className="text-[11px] font-semibold text-muted-foreground">
                                                            Required Headcount
                                                        </Label>
                                                        <Input
                                                            type="number"
                                                            min={1}
                                                            max={500}
                                                            disabled={
                                                                !canEditPositionStructure
                                                            }
                                                            value={
                                                                line.required_headcount
                                                            }
                                                            aria-invalid={Boolean(
                                                                headcountError,
                                                            )}
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
                                                            className={invalidControlClass(
                                                                '',
                                                                index,
                                                                'required_headcount',
                                                            )}
                                                        />
                                                        {headcountError && (
                                                            <p className="text-[11px] text-destructive">
                                                                {headcountError}
                                                            </p>
                                                        )}
                                                    </div>

                                                    {canEditPositionStructure && (
                                                        <div className="col-span-4 flex items-end justify-end pt-5 sm:col-span-2">
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="sm"
                                                                disabled={
                                                                    !canRemoveRequirementPositionLine(
                                                                        {
                                                                            canEditPositionStructure,
                                                                            isPreparationEditable,
                                                                            positionCount:
                                                                                data
                                                                                    .positions
                                                                                    .length,
                                                                        },
                                                                    )
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
                                                    )}

                                                    {/* Salary Range Section */}
                                                    <div className="col-span-12 space-y-2 rounded-lg border border-border/50 bg-background/50 p-3">
                                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                                            <div className="flex flex-wrap items-center gap-1.5 text-[11px] font-semibold text-muted-foreground">
                                                                <span>
                                                                    Approved
                                                                    Salary Range
                                                                </span>
                                                                <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-bold text-foreground">
                                                                    {options.currency_code ||
                                                                        'AED'}
                                                                </span>
                                                                {isCopiedFromPosition && (
                                                                    <span className="rounded bg-emerald-500/10 px-1.5 py-0.5 text-[10px] font-medium text-emerald-600 dark:text-emerald-400">
                                                                        Copied
                                                                        from
                                                                        position
                                                                        defaults
                                                                    </span>
                                                                )}
                                                                {isEditedFromPosition && (
                                                                    <span className="rounded bg-amber-500/10 px-1.5 py-0.5 text-[10px] font-medium text-amber-600 dark:text-amber-400">
                                                                        Custom
                                                                        override
                                                                    </span>
                                                                )}
                                                            </div>
                                                            {canResetToDefault && (
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        handleResetPositionSalary(
                                                                            index,
                                                                        )
                                                                    }
                                                                    className="h-6 gap-1 px-2 text-[11px] text-muted-foreground hover:text-foreground"
                                                                    title="Reset salary range to position defaults"
                                                                >
                                                                    <RotateCcw className="h-3 w-3" />
                                                                    <span>
                                                                        Reset to
                                                                        Position
                                                                        Default
                                                                    </span>
                                                                </Button>
                                                            )}
                                                        </div>

                                                        <div className="grid grid-cols-12 gap-3">
                                                            <div
                                                                className="col-span-12 space-y-1 sm:col-span-6"
                                                                data-requirement-field={`positions.${index}.salary_min`}
                                                            >
                                                                <Label className="text-[11px] font-medium text-muted-foreground">
                                                                    Minimum
                                                                    Salary (
                                                                    {options.currency_code ||
                                                                        'AED'}
                                                                    )
                                                                </Label>
                                                                <Input
                                                                    type="number"
                                                                    step="0.01"
                                                                    min="0"
                                                                    placeholder="e.g. 5000.00"
                                                                    value={
                                                                        line.salary_min ??
                                                                        ''
                                                                    }
                                                                    aria-invalid={Boolean(
                                                                        minSalaryError,
                                                                    )}
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        handlePositionChange(
                                                                            index,
                                                                            'salary_min',
                                                                            e
                                                                                .target
                                                                                .value,
                                                                        )
                                                                    }
                                                                    className={
                                                                        minSalaryError
                                                                            ? 'border-destructive ring-1 ring-destructive/40'
                                                                            : ''
                                                                    }
                                                                />
                                                                {minSalaryError && (
                                                                    <p className="text-[11px] text-destructive">
                                                                        {
                                                                            minSalaryError
                                                                        }
                                                                    </p>
                                                                )}
                                                            </div>

                                                            <div
                                                                className="col-span-12 space-y-1 sm:col-span-6"
                                                                data-requirement-field={`positions.${index}.salary_max`}
                                                            >
                                                                <Label className="text-[11px] font-medium text-muted-foreground">
                                                                    Maximum
                                                                    Salary (
                                                                    {options.currency_code ||
                                                                        'AED'}
                                                                    )
                                                                </Label>
                                                                <Input
                                                                    type="number"
                                                                    step="0.01"
                                                                    min="0"
                                                                    placeholder="e.g. 8000.00"
                                                                    value={
                                                                        line.salary_max ??
                                                                        ''
                                                                    }
                                                                    aria-invalid={Boolean(
                                                                        maxSalaryError,
                                                                    )}
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        handlePositionChange(
                                                                            index,
                                                                            'salary_max',
                                                                            e
                                                                                .target
                                                                                .value,
                                                                        )
                                                                    }
                                                                    className={
                                                                        maxSalaryError
                                                                            ? 'border-destructive ring-1 ring-destructive/40'
                                                                            : ''
                                                                    }
                                                                />
                                                                {maxSalaryError && (
                                                                    <p className="text-[11px] text-destructive">
                                                                        {
                                                                            maxSalaryError
                                                                        }
                                                                    </p>
                                                                )}
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div className="col-span-12 space-y-1.5">
                                                        <Label className="text-[11px] font-semibold text-muted-foreground">
                                                            Position Specific
                                                            Notes /
                                                            Certifications
                                                            (optional)
                                                        </Label>
                                                        <Input
                                                            placeholder="e.g. Valid BOSIET required, min 3 years offshore experience"
                                                            disabled={
                                                                !canEditPositionStructure
                                                            }
                                                            value={
                                                                line.line_notes ||
                                                                ''
                                                            }
                                                            onChange={(e) =>
                                                                handlePositionChange(
                                                                    index,
                                                                    'line_notes',
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                        />
                                                    </div>
                                                </div>
                                            </div>
                                        );
                                    })}
                                    {canEditPositionStructure && (
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
                                    )}
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
                                                    : 'Select file (PDF, Word, Excel, Images up to 20MB)'}
                                            </span>
                                        </label>
                                        <input
                                            id="attachment"
                                            type="file"
                                            className="hidden"
                                            accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.rtf,.jpg,.jpeg,.png,.webp"
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

                        <SheetFooter className="flex flex-col gap-3 border-t border-border/60 p-6 sm:flex-row sm:items-center sm:justify-between">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={requestClose}
                                disabled={busy}
                                className="w-full sm:w-auto"
                            >
                                Cancel
                            </Button>
                            <div className="flex w-full flex-col items-stretch gap-2 sm:w-auto sm:items-end">
                                {showSubmitAction ? (
                                    <p
                                        data-requirement-submit-compact-status
                                        className="flex items-center justify-end gap-1 text-xs text-muted-foreground"
                                    >
                                        {submissionReadiness.ready ? (
                                            <>
                                                <CheckCircle2 className="h-3.5 w-3.5 text-emerald-600" />
                                                Ready for approval
                                            </>
                                        ) : (
                                            <>
                                                <AlertCircle className="h-3.5 w-3.5 text-amber-600" />
                                                {
                                                    submissionReadiness.remaining_count
                                                }{' '}
                                                item
                                                {submissionReadiness.remaining_count ===
                                                1
                                                    ? ''
                                                    : 's'}{' '}
                                                missing
                                            </>
                                        )}
                                    </p>
                                ) : null}
                                <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                                    <Button
                                        type="submit"
                                        variant={
                                            showSubmitAction
                                                ? 'outline'
                                                : 'default'
                                        }
                                        disabled={busy}
                                        className="gap-2"
                                        onClick={(event) =>
                                            queueSubmit(event, false)
                                        }
                                    >
                                        {busy && (
                                            <Loader2 className="h-4 w-4 animate-spin" />
                                        )}
                                        {isEditing
                                            ? draftSaveLabel
                                            : 'Save as Draft'}
                                    </Button>
                                    {showSubmitAction ? (
                                        <Button
                                            type="submit"
                                            disabled={busy}
                                            className="gap-2"
                                            onClick={(event) =>
                                                queueSubmit(event, true)
                                            }
                                        >
                                            {busy && (
                                                <Loader2 className="h-4 w-4 animate-spin" />
                                            )}
                                            {submitSaveLabel}
                                        </Button>
                                    ) : null}
                                </div>
                            </div>
                        </SheetFooter>
                    </form>
                </SheetContent>
            </Sheet>

            <AlertDialog
                open={confirmDiscardOpen}
                onOpenChange={setConfirmDiscardOpen}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Discard unsaved changes?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            You have unsaved requirement details. Closing now
                            discards those changes.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel
                            onClick={() => {
                                pendingCloseRef.current = false;
                            }}
                        >
                            Keep editing
                        </AlertDialogCancel>
                        <AlertDialogAction onClick={confirmDiscard}>
                            Discard changes
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {/* Duplicate Decision Dialog */}
            <DuplicateDecisionDialog
                open={isDuplicateDialogOpen}
                onOpenChange={(open) => {
                    setIsDuplicateDialogOpen(open);

                    if (!open) {
                        // Closing without an explicit create-separate decision
                        // clears stale submit intent (return/review, dismiss, Esc).
                        const decision = resolveDuplicateDialogSubmitIntent(
                            pendingSubmitForApprovalRef.current,
                            'dismiss',
                        );

                        if (decision.clearPendingIntent) {
                            pendingSubmitForApprovalRef.current = false;
                        }
                    }
                }}
                matches={duplicateMatches}
                onAddHeadcount={handleAddHeadcountToMatch}
                onCreateSeparateBatch={() => {
                    // Capture intent before closing — onOpenChange(false) also
                    // clears the ref, so read it first.
                    const decision = resolveDuplicateDialogSubmitIntent(
                        pendingSubmitForApprovalRef.current,
                        'create_separate',
                    );
                    pendingSubmitForApprovalRef.current = false;
                    setIsDuplicateDialogOpen(false);
                    submitRequisition(true, decision.submitForApproval);
                }}
                onReturnAndReview={() => {
                    const decision = resolveDuplicateDialogSubmitIntent(
                        pendingSubmitForApprovalRef.current,
                        'return_and_review',
                    );

                    if (decision.clearPendingIntent) {
                        pendingSubmitForApprovalRef.current = false;
                    }

                    setIsDuplicateDialogOpen(false);
                }}
                isSubmitting={busy}
            />
        </>
    );
}
