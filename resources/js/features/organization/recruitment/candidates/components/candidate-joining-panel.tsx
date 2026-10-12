import { Link, useForm } from '@inertiajs/react';
import {
    CalendarCheck,
    AlertTriangle,
    Undo2,
    CheckCircle2,
    UserPlus,
    User,
    Link2,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { nowInCompanyDate } from '@/lib/company-timezone';
import {
    joiningKnownGeneralError,
    joiningUnrenderedErrors,
} from '../lib/joining-form-errors';
import type { CandidateDetail } from '../types';
import { CandidateReadinessFields } from './candidate-readiness-fields';
import { CandidateJoiningScheduleBadge } from './candidate-stage-badge';

export function CandidateJoiningPanel({
    candidate,
}: {
    candidate: CandidateDetail;
}) {
    const joining = candidate.joining;
    const isJoined = candidate.stage === 'joined';
    const hasAcceptedOffer = candidate.current_offer?.status === 'accepted';
    const isOfferJolAwaitingJoin =
        candidate.stage === 'offer_jol' && hasAcceptedOffer;

    // Joining preparation lives inside Offer/JOL after acceptance; Joined shows confirmation details.
    const shouldDisplay =
        isOfferJolAwaitingJoin || isJoined || hasAcceptedOffer;

    const [isConfirmOpen, setIsConfirmOpen] = useState(false);
    const [isCorrectOpen, setIsCorrectOpen] = useState(false);
    const [isLinkOpen, setIsLinkOpen] = useState(false);

    const canConvert = joining?.can_convert ?? candidate.can_convert;
    const conversionStatus =
        joining?.conversion_status ?? candidate.conversion_status;
    const linkedEmployee =
        joining?.linked_employee ?? candidate.linked_employee;

    const companyToday = nowInCompanyDate(candidate.timezone);

    // Readiness Form
    const readinessForm = useForm({
        expected_joining_date:
            joining?.expected_joining_date ??
            candidate.expected_joining_date ??
            '',
        joining_readiness_status:
            joining?.readiness_status ??
            candidate.joining_readiness_status ??
            'pending',
        joining_readiness_notes:
            joining?.readiness_notes ?? candidate.joining_readiness_notes ?? '',
        joining_blocker_notes:
            joining?.blocker_notes ?? candidate.joining_blocker_notes ?? '',
        reason: '',
        lock_version: candidate.lock_version,
    });

    // Confirm Joined Form
    const confirmForm = useForm({
        actual_joining_date:
            joining?.expected_joining_date ??
            candidate.expected_joining_date ??
            companyToday,
        notes: '',
        lock_version: candidate.lock_version,
    });

    // Correct / Undo Form
    const correctForm = useForm({
        reason: '',
        lock_version: candidate.lock_version,
    });

    // Link Existing Employee Form
    const linkForm = useForm<{
        employee_id: string;
        confirmed: boolean;
        reason: string;
        lock_version: number;
        candidate?: string;
    }>({
        employee_id: '',
        confirmed: false,
        reason: '',
        lock_version: candidate.lock_version,
    });

    useEffect(() => {
        readinessForm.setData({
            expected_joining_date:
                joining?.expected_joining_date ??
                candidate.expected_joining_date ??
                '',
            joining_readiness_status:
                joining?.readiness_status ??
                candidate.joining_readiness_status ??
                'pending',
            joining_readiness_notes:
                joining?.readiness_notes ??
                candidate.joining_readiness_notes ??
                '',
            joining_blocker_notes:
                joining?.blocker_notes ?? candidate.joining_blocker_notes ?? '',
            reason: '',
            lock_version: candidate.lock_version,
        });
        readinessForm.clearErrors();
        // Sync when server props refresh after save.
        // eslint-disable-next-line react-hooks/exhaustive-deps -- intentional candidate snapshot sync
    }, [
        candidate.id,
        candidate.lock_version,
        joining?.expected_joining_date,
        joining?.readiness_status,
        joining?.readiness_notes,
        joining?.blocker_notes,
    ]);

    if (!shouldDisplay) {
        return null;
    }

    const canEditReadiness =
        isOfferJolAwaitingJoin && candidate.can_update_readiness;

    const handleReadinessSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        readinessForm.transform((data) => ({
            expected_joining_date: data.expected_joining_date || null,
            joining_readiness_status: data.joining_readiness_status,
            joining_readiness_notes: data.joining_readiness_notes || null,
            joining_blocker_notes: data.joining_blocker_notes || null,
            reason: data.reason || null,
            lock_version: candidate.lock_version,
            expected_stage: candidate.stage,
        }));
        readinessForm.post(
            `/organization/recruitment/candidates/${candidate.id}/joining-readiness`,
            {
                preserveScroll: true,
                onFinish: () => readinessForm.transform((data) => data),
            },
        );
    };

    const handleConfirmSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        confirmForm.post(
            `/organization/recruitment/candidates/${candidate.id}/confirm-joined`,
            {
                preserveScroll: true,
                onSuccess: () => {
                    setIsConfirmOpen(false);
                    confirmForm.reset('notes');
                },
            },
        );
    };

    const handleCorrectSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        correctForm.post(
            `/organization/recruitment/candidates/${candidate.id}/correct-joining`,
            {
                preserveScroll: true,
                onSuccess: () => {
                    setIsCorrectOpen(false);
                    correctForm.reset('reason');
                },
            },
        );
    };

    const handleLinkSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        linkForm.post(
            `/organization/recruitment/candidates/${candidate.id}/link-employee`,
            {
                preserveScroll: true,
                onSuccess: () => {
                    setIsLinkOpen(false);
                    linkForm.reset();
                },
            },
        );
    };

    const readinessGeneralError = joiningKnownGeneralError(
        readinessForm.errors,
    );
    const readinessUnrenderedErrors = joiningUnrenderedErrors(
        readinessForm.errors,
        [
            'expected_joining_date',
            'joining_readiness_status',
            'joining_readiness_notes',
            'joining_blocker_notes',
            'reason',
        ],
    );

    const confirmGeneralError = joiningKnownGeneralError(confirmForm.errors);
    const confirmUnrenderedErrors = joiningUnrenderedErrors(
        confirmForm.errors,
        ['actual_joining_date', 'notes'],
    );

    const correctGeneralError = joiningKnownGeneralError(correctForm.errors);
    const correctUnrenderedErrors = joiningUnrenderedErrors(
        correctForm.errors,
        ['reason'],
    );

    const readinessStatus =
        joining?.readiness_status ??
        candidate.joining_readiness_status ??
        'pending';
    const isReady = readinessStatus === 'ready';

    return (
        <Card id="joining" className="scroll-mt-4">
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-3">
                <div className="flex items-center gap-2">
                    <CalendarCheck className="size-5 text-muted-foreground" />
                    <CardTitle className="text-base font-semibold">
                        Joining & Readiness
                    </CardTitle>
                </div>
                <div className="flex items-center gap-2">
                    {isOfferJolAwaitingJoin && candidate.can_confirm_joined && (
                        <Button
                            variant="default"
                            size="sm"
                            className="bg-emerald-600 text-white hover:bg-emerald-700"
                            onClick={() => {
                                confirmForm.clearErrors();
                                confirmForm.setData({
                                    actual_joining_date:
                                        joining?.expected_joining_date ??
                                        candidate.expected_joining_date ??
                                        companyToday,
                                    notes: '',
                                    lock_version: candidate.lock_version,
                                });
                                setIsConfirmOpen(true);
                            }}
                        >
                            <CheckCircle2 className="mr-1.5 size-4" />
                            Confirm Joined
                        </Button>
                    )}

                    {isOfferJolAwaitingJoin &&
                        !candidate.can_confirm_joined &&
                        !isReady && (
                            <span className="inline-flex items-center gap-1 text-xs text-amber-700 dark:text-amber-300">
                                <AlertTriangle className="size-3.5 shrink-0" />
                                Confirm Joined requires readiness = Ready
                                {(joining?.blocker_notes ??
                                candidate.joining_blocker_notes)
                                    ? `: ${joining?.blocker_notes ?? candidate.joining_blocker_notes}`
                                    : ''}
                            </span>
                        )}

                    {isJoined &&
                        canConvert &&
                        conversionStatus !== 'converted' && (
                            <>
                                <Button
                                    asChild
                                    variant="default"
                                    size="sm"
                                    className="bg-primary text-primary-foreground hover:bg-primary/90"
                                >
                                    <Link
                                        href={`/organization/employees/create?candidate_id=${candidate.id}`}
                                    >
                                        <UserPlus className="mr-1.5 size-4" />
                                        Create Employee
                                    </Link>
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => {
                                        linkForm.clearErrors();
                                        linkForm.setData({
                                            employee_id: '',
                                            confirmed: false,
                                            reason: '',
                                            lock_version:
                                                candidate.lock_version,
                                        });
                                        setIsLinkOpen(true);
                                    }}
                                >
                                    <Link2 className="mr-1.5 size-4" />
                                    Link Existing
                                </Button>
                            </>
                        )}

                    {isJoined && candidate.can_correct_joined && (
                        <Button
                            variant="outline"
                            size="sm"
                            className="text-destructive hover:bg-destructive/10"
                            onClick={() => {
                                correctForm.clearErrors();
                                correctForm.setData({
                                    reason: '',
                                    lock_version: candidate.lock_version,
                                });
                                setIsCorrectOpen(true);
                            }}
                        >
                            <Undo2 className="mr-1.5 size-4" />
                            Undo Joined
                        </Button>
                    )}
                </div>
            </CardHeader>

            <CardContent className="space-y-4 pt-1">
                {canEditReadiness ? (
                    <form
                        onSubmit={handleReadinessSubmit}
                        className="space-y-4"
                    >
                        {readinessGeneralError ? (
                            <p
                                className="rounded-md bg-destructive/15 p-2 text-xs text-destructive"
                                role="alert"
                            >
                                {readinessGeneralError}
                            </p>
                        ) : null}
                        {readinessUnrenderedErrors.map((msg) => (
                            <p
                                key={msg}
                                className="rounded-md bg-destructive/15 p-2 text-xs text-destructive"
                                role="alert"
                            >
                                {msg}
                            </p>
                        ))}
                        {readinessForm.recentlySuccessful &&
                        Object.keys(readinessForm.errors).length === 0 ? (
                            <p
                                className="text-sm text-muted-foreground"
                                aria-live="polite"
                            >
                                Joining readiness saved.
                            </p>
                        ) : null}

                        <div className="flex flex-wrap items-center gap-2">
                            <CandidateJoiningScheduleBadge
                                urgency={
                                    joining?.schedule_urgency ??
                                    candidate.joining_schedule_urgency
                                }
                                label={
                                    joining?.schedule_label ??
                                    candidate.joining_schedule_label
                                }
                            />
                        </div>

                        <CandidateReadinessFields
                            form={readinessForm}
                            idPrefix={`joining-inline-${candidate.id}`}
                            twoColumn
                        />

                        <div className="flex justify-end">
                            <Button
                                type="submit"
                                size="sm"
                                disabled={readinessForm.processing}
                            >
                                {readinessForm.processing
                                    ? 'Saving…'
                                    : 'Save Readiness'}
                            </Button>
                        </div>
                    </form>
                ) : (
                    <>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div className="space-y-1">
                                <span className="text-xs font-medium text-muted-foreground">
                                    Readiness Status
                                </span>
                                <div>
                                    {isJoined ? (
                                        <Badge
                                            variant="default"
                                            className="bg-emerald-600 text-white"
                                        >
                                            Joined Confirmed
                                        </Badge>
                                    ) : (
                                        <Badge
                                            variant={
                                                isReady
                                                    ? 'default'
                                                    : 'secondary'
                                            }
                                            className={
                                                isReady
                                                    ? 'bg-emerald-600 text-white hover:bg-emerald-600'
                                                    : 'bg-amber-100 text-amber-800 hover:bg-amber-100 dark:bg-amber-950 dark:text-amber-200'
                                            }
                                        >
                                            {isReady ? 'Ready' : 'Pending'}
                                        </Badge>
                                    )}
                                </div>
                            </div>

                            <div className="space-y-1">
                                <span className="text-xs font-medium text-muted-foreground">
                                    Expected Joining Date
                                </span>
                                <div className="flex items-center gap-2">
                                    <span className="text-sm font-medium">
                                        {joining?.expected_joining_date ??
                                            candidate.expected_joining_date ??
                                            '—'}
                                    </span>
                                    {!isJoined && (
                                        <CandidateJoiningScheduleBadge
                                            urgency={
                                                joining?.schedule_urgency ??
                                                candidate.joining_schedule_urgency
                                            }
                                            label={
                                                joining?.schedule_label ??
                                                candidate.joining_schedule_label
                                            }
                                        />
                                    )}
                                </div>
                            </div>

                            {isJoined && (
                                <>
                                    <div className="space-y-1">
                                        <span className="text-xs font-medium text-muted-foreground">
                                            Actual Joining Date
                                        </span>
                                        <div>
                                            <span className="text-sm font-medium text-emerald-600 dark:text-emerald-400">
                                                {joining?.actual_joining_date ??
                                                    candidate.actual_joining_date ??
                                                    '—'}
                                            </span>
                                        </div>
                                    </div>

                                    <div className="space-y-1">
                                        <span className="text-xs font-medium text-muted-foreground">
                                            Confirmed By
                                        </span>
                                        <div>
                                            <span className="text-sm">
                                                {joining?.joined_by_name ??
                                                    candidate.joined_by_name ??
                                                    '—'}
                                            </span>
                                        </div>
                                    </div>
                                </>
                            )}
                        </div>

                        {(joining?.readiness_notes ||
                            candidate.joining_readiness_notes ||
                            joining?.blocker_notes ||
                            candidate.joining_blocker_notes) && (
                            <div className="grid grid-cols-1 gap-3 rounded-md bg-muted/40 p-3 sm:grid-cols-2">
                                {(joining?.readiness_notes ||
                                    candidate.joining_readiness_notes) && (
                                    <div>
                                        <span className="text-xs font-medium text-muted-foreground">
                                            Readiness Notes:
                                        </span>
                                        <p className="mt-0.5 text-xs text-foreground">
                                            {joining?.readiness_notes ??
                                                candidate.joining_readiness_notes}
                                        </p>
                                    </div>
                                )}
                                {(joining?.blocker_notes ||
                                    candidate.joining_blocker_notes) && (
                                    <div>
                                        <div className="flex items-center gap-1 text-xs font-medium text-destructive">
                                            <AlertTriangle className="size-3.5" />
                                            <span>Blocker Notes:</span>
                                        </div>
                                        <p className="mt-0.5 text-xs text-foreground">
                                            {joining?.blocker_notes ??
                                                candidate.joining_blocker_notes}
                                        </p>
                                    </div>
                                )}
                            </div>
                        )}
                    </>
                )}

                {isJoined && (
                    <div className="mt-3 rounded-md border border-border/60 bg-muted/20 p-3">
                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex items-center gap-2">
                                <span className="text-xs font-medium text-muted-foreground">
                                    Employee Conversion:
                                </span>
                                {conversionStatus === 'converted' ? (
                                    <Badge
                                        variant="default"
                                        className="bg-emerald-600 text-white hover:bg-emerald-600"
                                    >
                                        Converted
                                    </Badge>
                                ) : (
                                    <Badge
                                        variant="secondary"
                                        className="bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200"
                                    >
                                        Pending HR Review
                                    </Badge>
                                )}
                            </div>
                            {conversionStatus === 'converted' &&
                                linkedEmployee && (
                                    <div className="flex items-center gap-2 text-xs">
                                        <span className="text-muted-foreground">
                                            Linked Employee:
                                        </span>
                                        {linkedEmployee.can_view &&
                                        linkedEmployee.id ? (
                                            <Link
                                                href={`/organization/employees/${linkedEmployee.id}`}
                                                className="flex items-center gap-1 font-medium text-primary hover:underline"
                                            >
                                                <User className="size-3.5" />
                                                {linkedEmployee.name} (
                                                {linkedEmployee.employee_no})
                                            </Link>
                                        ) : (
                                            <span className="flex items-center gap-1 font-medium text-muted-foreground">
                                                <User className="size-3.5" />
                                                Restricted
                                            </span>
                                        )}
                                    </div>
                                )}
                        </div>
                    </div>
                )}
            </CardContent>

            {/* Dialog: Confirm Joined */}
            <Dialog
                open={isConfirmOpen}
                onOpenChange={(open) => {
                    setIsConfirmOpen(open);

                    if (!open) {
                        confirmForm.clearErrors();
                    }
                }}
            >
                <DialogContent className="sm:max-w-md">
                    <form onSubmit={handleConfirmSubmit} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Confirm Actual Joining</DialogTitle>
                            <DialogDescription>
                                Confirm that {candidate.name} has physically
                                joined and transition their stage to Joined.
                            </DialogDescription>
                        </DialogHeader>

                        {confirmGeneralError && (
                            <div className="rounded-md bg-destructive/15 p-2 text-xs text-destructive">
                                {confirmGeneralError}
                            </div>
                        )}

                        {confirmUnrenderedErrors.map((msg, idx) => (
                            <div
                                key={idx}
                                className="rounded-md bg-destructive/15 p-2 text-xs text-destructive"
                            >
                                {msg}
                            </div>
                        ))}

                        <div className="space-y-3">
                            <div className="space-y-1">
                                <Label htmlFor="actual_joining_date">
                                    Actual Joining Date *
                                </Label>
                                <Input
                                    id="actual_joining_date"
                                    type="date"
                                    max={companyToday}
                                    value={confirmForm.data.actual_joining_date}
                                    onChange={(e) =>
                                        confirmForm.setData(
                                            'actual_joining_date',
                                            e.target.value,
                                        )
                                    }
                                />
                                <p className="text-[11px] text-muted-foreground">
                                    Valid historical dates allowed. Future dates
                                    not permitted.
                                </p>
                                <InputError
                                    message={
                                        confirmForm.errors.actual_joining_date
                                    }
                                />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="confirm_notes">Notes</Label>
                                <Textarea
                                    id="confirm_notes"
                                    rows={2}
                                    placeholder="Optional joining confirmation notes"
                                    value={confirmForm.data.notes}
                                    onChange={(e) =>
                                        confirmForm.setData(
                                            'notes',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={confirmForm.errors.notes}
                                />
                            </div>
                        </div>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={confirmForm.processing}
                                onClick={() => setIsConfirmOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                className="bg-emerald-600 text-white hover:bg-emerald-700"
                                disabled={confirmForm.processing}
                            >
                                {confirmForm.processing
                                    ? 'Confirming...'
                                    : 'Confirm Joined'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Dialog: Correct / Undo Joined */}
            <Dialog
                open={isCorrectOpen}
                onOpenChange={(open) => {
                    setIsCorrectOpen(open);

                    if (!open) {
                        correctForm.clearErrors();
                    }
                }}
            >
                <DialogContent className="sm:max-w-md">
                    <form onSubmit={handleCorrectSubmit} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>
                                Correct / Undo Joined Status
                            </DialogTitle>
                            <DialogDescription>
                                Revert candidate back to Offer/JOL. Requires a
                                management audit reason.
                            </DialogDescription>
                        </DialogHeader>

                        {correctGeneralError && (
                            <div className="rounded-md bg-destructive/15 p-2 text-xs text-destructive">
                                {correctGeneralError}
                            </div>
                        )}

                        {correctUnrenderedErrors.map((msg, idx) => (
                            <div
                                key={idx}
                                className="rounded-md bg-destructive/15 p-2 text-xs text-destructive"
                            >
                                {msg}
                            </div>
                        ))}

                        <div className="space-y-3">
                            <div className="space-y-1">
                                <Label htmlFor="correct_reason">
                                    Correction Reason *
                                </Label>
                                <Textarea
                                    id="correct_reason"
                                    rows={3}
                                    placeholder="Explain why this joined status is being corrected (at least 3 characters)"
                                    value={correctForm.data.reason}
                                    onChange={(e) =>
                                        correctForm.setData(
                                            'reason',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={correctForm.errors.reason}
                                />
                            </div>
                        </div>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={correctForm.processing}
                                onClick={() => setIsCorrectOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={correctForm.processing}
                            >
                                {correctForm.processing
                                    ? 'Reverting...'
                                    : 'Undo Joined'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Dialog: Link Existing Employee */}
            <Dialog
                open={isLinkOpen}
                onOpenChange={(open) => {
                    setIsLinkOpen(open);

                    if (!open) {
                        linkForm.clearErrors();
                    }
                }}
            >
                <DialogContent className="sm:max-w-md">
                    <form onSubmit={handleLinkSubmit} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Link Existing Employee</DialogTitle>
                            <DialogDescription>
                                Link this joined candidate to an existing
                                company employee profile. This action cannot be
                                undone.
                            </DialogDescription>
                        </DialogHeader>

                        {linkForm.errors.candidate && (
                            <div className="rounded-md bg-destructive/15 p-2 text-xs text-destructive">
                                {linkForm.errors.candidate}
                            </div>
                        )}

                        <div className="space-y-3">
                            <div className="space-y-1">
                                <Label htmlFor="link_employee_id">
                                    Employee ID *
                                </Label>
                                <Input
                                    id="link_employee_id"
                                    type="number"
                                    placeholder="Enter employee ID"
                                    value={linkForm.data.employee_id}
                                    onChange={(e) =>
                                        linkForm.setData(
                                            'employee_id',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={linkForm.errors.employee_id}
                                />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="link_reason">
                                    Audit Reason *
                                </Label>
                                <Textarea
                                    id="link_reason"
                                    rows={2}
                                    placeholder="Reason for manual employee link"
                                    value={linkForm.data.reason}
                                    onChange={(e) =>
                                        linkForm.setData(
                                            'reason',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError message={linkForm.errors.reason} />
                            </div>

                            <div className="flex items-start gap-2 pt-2">
                                <input
                                    id="link_confirmed"
                                    type="checkbox"
                                    className="mt-1 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                                    checked={linkForm.data.confirmed}
                                    onChange={(e) =>
                                        linkForm.setData(
                                            'confirmed',
                                            e.target.checked,
                                        )
                                    }
                                />
                                <Label
                                    htmlFor="link_confirmed"
                                    className="text-xs leading-normal text-muted-foreground"
                                >
                                    I confirm that this candidate corresponds to
                                    the selected employee and should be
                                    permanently linked.
                                </Label>
                            </div>
                            <InputError message={linkForm.errors.confirmed} />
                        </div>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={linkForm.processing}
                                onClick={() => setIsLinkOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={
                                    linkForm.processing ||
                                    !linkForm.data.confirmed ||
                                    !linkForm.data.employee_id
                                }
                            >
                                {linkForm.processing
                                    ? 'Linking...'
                                    : 'Confirm Link'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </Card>
    );
}
