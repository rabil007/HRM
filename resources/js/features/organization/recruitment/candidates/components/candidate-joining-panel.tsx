import { useForm } from '@inertiajs/react';
import {
    CalendarCheck,
    AlertTriangle,
    Undo2,
    CheckCircle2,
} from 'lucide-react';
import { useState } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
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
import type { CandidateDetail } from '../types';
import { CandidateJoiningScheduleBadge } from './candidate-stage-badge';

export function CandidateJoiningPanel({
    candidate,
}: {
    candidate: CandidateDetail;
}) {
    const joining = candidate.joining;
    const isJoined = candidate.stage === 'joined';
    const isJoiningStage = candidate.stage === 'joining';

    // Show joining panel if candidate has reached Joining or Joined stage, or has an accepted offer
    const shouldDisplay =
        isJoiningStage ||
        isJoined ||
        candidate.current_offer?.status === 'accepted';

    const [isReadinessOpen, setIsReadinessOpen] = useState(false);
    const [isConfirmOpen, setIsConfirmOpen] = useState(false);
    const [isCorrectOpen, setIsCorrectOpen] = useState(false);

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

    if (!shouldDisplay) {
        return null;
    }

    const handleReadinessSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        readinessForm.post(
            `/organization/recruitment/candidates/${candidate.id}/joining-readiness`,
            {
                preserveScroll: true,
                onSuccess: () => {
                    setIsReadinessOpen(false);
                    readinessForm.reset('reason');
                },
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

    const readinessGeneralError =
        (readinessForm.errors as Record<string, string | undefined>).error ||
        (readinessForm.errors as Record<string, string | undefined>).general ||
        (readinessForm.errors as Record<string, string | undefined>)
            .lock_version;

    const confirmGeneralError =
        (confirmForm.errors as Record<string, string | undefined>).error ||
        (confirmForm.errors as Record<string, string | undefined>).general ||
        (confirmForm.errors as Record<string, string | undefined>).lock_version;

    const correctGeneralError =
        (correctForm.errors as Record<string, string | undefined>).error ||
        (correctForm.errors as Record<string, string | undefined>).general ||
        (correctForm.errors as Record<string, string | undefined>).lock_version;

    const readinessStatus =
        joining?.readiness_status ??
        candidate.joining_readiness_status ??
        'pending';
    const isReady = readinessStatus === 'ready';

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-3">
                <div className="flex items-center gap-2">
                    <CalendarCheck className="size-5 text-muted-foreground" />
                    <CardTitle className="text-base font-semibold">
                        Joining & Readiness
                    </CardTitle>
                </div>
                <div className="flex items-center gap-2">
                    {isJoiningStage && candidate.can_update_readiness && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => {
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
                                        joining?.blocker_notes ??
                                        candidate.joining_blocker_notes ??
                                        '',
                                    reason: '',
                                    lock_version: candidate.lock_version,
                                });
                                setIsReadinessOpen(true);
                            }}
                        >
                            Update Readiness
                        </Button>
                    )}

                    {isJoiningStage && candidate.can_confirm_joined && (
                        <Button
                            variant="default"
                            size="sm"
                            className="bg-emerald-600 text-white hover:bg-emerald-700"
                            onClick={() => {
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

                    {isJoined && candidate.can_correct_joined && (
                        <Button
                            variant="outline"
                            size="sm"
                            className="text-destructive hover:bg-destructive/10"
                            onClick={() => {
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
                                    variant={isReady ? 'default' : 'secondary'}
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

                {/* Readiness notes or blocker notes */}
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
            </CardContent>

            {/* Dialog: Update Readiness */}
            <Dialog open={isReadinessOpen} onOpenChange={setIsReadinessOpen}>
                <DialogContent className="sm:max-w-lg">
                    <form
                        onSubmit={handleReadinessSubmit}
                        className="space-y-4"
                    >
                        <DialogHeader>
                            <DialogTitle>Update Joining Readiness</DialogTitle>
                            <DialogDescription>
                                Set operational readiness status and adjust
                                expected joining schedule.
                            </DialogDescription>
                        </DialogHeader>

                        {readinessGeneralError && (
                            <div className="rounded-md bg-destructive/15 p-2 text-xs text-destructive">
                                {readinessGeneralError}
                            </div>
                        )}

                        <div className="space-y-3">
                            <div className="space-y-1">
                                <Label htmlFor="expected_joining_date">
                                    Expected Joining Date
                                </Label>
                                <Input
                                    id="expected_joining_date"
                                    type="date"
                                    value={
                                        readinessForm.data.expected_joining_date
                                    }
                                    onChange={(e) =>
                                        readinessForm.setData(
                                            'expected_joining_date',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={
                                        readinessForm.errors
                                            .expected_joining_date
                                    }
                                />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="joining_readiness_status">
                                    Readiness Status *
                                </Label>
                                <AppSelect
                                    value={
                                        readinessForm.data
                                            .joining_readiness_status
                                    }
                                    onValueChange={(val) =>
                                        readinessForm.setData(
                                            'joining_readiness_status',
                                            val as 'pending' | 'ready',
                                        )
                                    }
                                >
                                    <AppSelectItem value="pending">
                                        Pending
                                    </AppSelectItem>
                                    <AppSelectItem value="ready">
                                        Ready
                                    </AppSelectItem>
                                </AppSelect>
                                <InputError
                                    message={
                                        readinessForm.errors
                                            .joining_readiness_status
                                    }
                                />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="joining_readiness_notes">
                                    Readiness Notes
                                </Label>
                                <Textarea
                                    id="joining_readiness_notes"
                                    rows={2}
                                    placeholder="e.g. Visa processed, flight booked, accommodation assigned."
                                    value={
                                        readinessForm.data
                                            .joining_readiness_notes
                                    }
                                    onChange={(e) =>
                                        readinessForm.setData(
                                            'joining_readiness_notes',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={
                                        readinessForm.errors
                                            .joining_readiness_notes
                                    }
                                />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="joining_blocker_notes">
                                    Blocker Notes (if any)
                                </Label>
                                <Textarea
                                    id="joining_blocker_notes"
                                    rows={2}
                                    placeholder="e.g. Medical clearance pending, missing original passport."
                                    value={
                                        readinessForm.data.joining_blocker_notes
                                    }
                                    onChange={(e) =>
                                        readinessForm.setData(
                                            'joining_blocker_notes',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={
                                        readinessForm.errors
                                            .joining_blocker_notes
                                    }
                                />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="readiness_reason">
                                    Audit Reason for Change
                                </Label>
                                <Input
                                    id="readiness_reason"
                                    placeholder="Optional reason for audit trail"
                                    value={readinessForm.data.reason}
                                    onChange={(e) =>
                                        readinessForm.setData(
                                            'reason',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={readinessForm.errors.reason}
                                />
                            </div>
                        </div>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setIsReadinessOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={readinessForm.processing}
                            >
                                {readinessForm.processing
                                    ? 'Saving...'
                                    : 'Save Readiness'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Dialog: Confirm Joined */}
            <Dialog open={isConfirmOpen} onOpenChange={setIsConfirmOpen}>
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
            <Dialog open={isCorrectOpen} onOpenChange={setIsCorrectOpen}>
                <DialogContent className="sm:max-w-md">
                    <form onSubmit={handleCorrectSubmit} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>
                                Correct / Undo Joined Status
                            </DialogTitle>
                            <DialogDescription>
                                Revert candidate back to Joining stage. Requires
                                a management audit reason.
                            </DialogDescription>
                        </DialogHeader>

                        {correctGeneralError && (
                            <div className="rounded-md bg-destructive/15 p-2 text-xs text-destructive">
                                {correctGeneralError}
                            </div>
                        )}

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
        </Card>
    );
}
