import { useForm, useHttp } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    ChevronDown,
    ChevronUp,
    Clock,
    FileSpreadsheet,
    History,
    Loader2,
    Ship,
    User,
} from 'lucide-react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import HistoricalCrewAssignmentController from '@/actions/App/Http/Controllers/Organization/HistoricalCrewAssignmentController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import {
    formatCompanyTimezoneLabel,
    useCompanyTimezone,
} from '@/lib/company-timezone';
import { formatDisplayDate } from '@/lib/format-date';
import type {
    CrewAssignmentFormOptions,
    HistoricalCrewAssignmentFormData,
    HistoricalCrewAssignmentPreviewData,
} from '../types';

interface AddPastDataDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    formOptions: CrewAssignmentFormOptions;
}

export function AddPastDataDialog({
    open,
    onOpenChange,
    formOptions,
}: AddPastDataDialogProps): ReactElement {
    const effectiveTimezone = useCompanyTimezone(formOptions.company_timezone);
    const timezoneLabel = formatCompanyTimezoneLabel(effectiveTimezone);

    const [activeTab, setActiveTab] = useState<'manual' | 'excel'>('manual');
    const [step, setStep] = useState<'form' | 'preview'>('form');
    const [showMoreDetails, setShowMoreDetails] = useState(false);
    const [previewData, setPreviewData] =
        useState<HistoricalCrewAssignmentPreviewData | null>(null);
    const [isValidating, setIsValidating] = useState(false);
    const [validationError, setValidationError] = useState<string | null>(null);

    const form = useForm<HistoricalCrewAssignmentFormData>({
        employee_id: '',
        vessel_id: '',
        rank_id: '',
        client_id: '',
        joined_vessel_at: '',
        disembarked_at: '',
        mobilisation_at: '',
        arrival_at: '',
        training_started_at: '',
        training_ended_at: '',
        ready_to_join_at: '',
        post_signoff_standby_at: '',
        travel_home_at: '',
        assignment_closed_at: '',
        remarks: '',
    });

    const http = useHttp();

    const handleClose = (nextOpen: boolean) => {
        if (!nextOpen) {
            form.reset();
            form.clearErrors();
            setStep('form');
            setPreviewData(null);
            setValidationError(null);
            setShowMoreDetails(false);
        }

        onOpenChange(nextOpen);
    };

    const handleEmployeeChange = (employeeIdStr: string) => {
        const empId = employeeIdStr ? Number(employeeIdStr) : '';
        form.setData((prev) => {
            const emp = formOptions.employees.find((e) => e.id === empId);

            return {
                ...prev,
                employee_id: empId,
                rank_id:
                    emp?.rank_id && !prev.rank_id
                        ? String(emp.rank_id)
                        : prev.rank_id,
            };
        });
    };

    const handleVesselChange = (vesselIdStr: string) => {
        const vId = vesselIdStr ? Number(vesselIdStr) : '';
        const vessel = formOptions.vessels.find((v) => v.id === vId);
        form.setData((prev) => ({
            ...prev,
            vessel_id: vId,
            client_id:
                vessel?.client_id != null
                    ? String(vessel.client_id)
                    : prev.client_id,
        }));
    };

    const handleValidate = async () => {
        form.clearErrors();
        setValidationError(null);

        // Quick client-side precheck
        const localErrors: Record<string, string> = {};

        if (!form.data.employee_id) {
            localErrors.employee_id = 'Please select an employee.';
        }

        if (!form.data.vessel_id) {
            localErrors.vessel_id = 'Please select a vessel.';
        }

        if (!form.data.rank_id) {
            localErrors.rank_id = 'Please select a rank.';
        }

        if (!form.data.joined_vessel_at) {
            localErrors.joined_vessel_at = 'Joined vessel date is required.';
        }

        if (!form.data.disembarked_at) {
            localErrors.disembarked_at = 'Disembarked date is required.';
        }

        if (Object.keys(localErrors).length > 0) {
            form.setError(localErrors);

            return;
        }

        setIsValidating(true);

        const payload = {
            employee_id: Number(form.data.employee_id),
            vessel_id: Number(form.data.vessel_id),
            rank_id: Number(form.data.rank_id),
            client_id: form.data.client_id ? Number(form.data.client_id) : null,
            joined_vessel_at: form.data.joined_vessel_at,
            disembarked_at: form.data.disembarked_at,
            mobilisation_at: form.data.mobilisation_at || null,
            arrival_at: form.data.arrival_at || null,
            training_started_at: form.data.training_started_at || null,
            training_ended_at: form.data.training_ended_at || null,
            ready_to_join_at: form.data.ready_to_join_at || null,
            post_signoff_standby_at: form.data.post_signoff_standby_at || null,
            travel_home_at: form.data.travel_home_at || null,
            assignment_closed_at: form.data.assignment_closed_at || null,
            remarks: form.data.remarks || null,
        };

        try {
            http.transform(() => payload);
            const response = await http.post(
                HistoricalCrewAssignmentController.preview.url(),
            );
            const preview =
                response as unknown as HistoricalCrewAssignmentPreviewData;
            setPreviewData(preview);
            setStep('preview');
        } catch (err: unknown) {
            const errorObj = err as {
                response?: {
                    data?: {
                        errors?: Record<string, string[]>;
                        message?: string;
                    };
                };
            };
            const errors = errorObj?.response?.data?.errors;

            if (errors) {
                const mapped: Record<string, string> = {};
                const topMessages: string[] = [];

                for (const [key, msgs] of Object.entries(errors)) {
                    if (Array.isArray(msgs) && msgs.length > 0) {
                        mapped[key] = msgs[0];

                        if (['assignment', 'overlap', 'dates'].includes(key)) {
                            topMessages.push(msgs[0]);
                        }
                    }
                }

                form.setError(mapped);

                if (topMessages.length > 0) {
                    setValidationError(topMessages.join(' '));
                } else if (errorObj?.response?.data?.message) {
                    setValidationError(errorObj.response.data.message);
                }
            } else {
                setValidationError(
                    'Validation failed. Please check the provided information.',
                );
            }
        } finally {
            setIsValidating(false);
        }
    };

    const handleConfirmSubmit = () => {
        form.post(HistoricalCrewAssignmentController.store.url(), {
            preserveScroll: true,
            onSuccess: () => {
                handleClose(false);
            },
            onError: (errs) => {
                setStep('form');

                if (errs.assignment || errs.overlap) {
                    setValidationError(
                        errs.assignment || errs.overlap || 'Validation error',
                    );
                }
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={handleClose}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <div className="flex items-center gap-2">
                        <div className="rounded-lg bg-primary/10 p-2 text-primary">
                            <History className="h-5 w-5" />
                        </div>
                        <div>
                            <DialogTitle className="text-xl font-semibold">
                                Add Past Crew Data
                            </DialogTitle>
                            <DialogDescription className="text-sm text-muted-foreground">
                                Record completed past crew movements without
                                affecting current operations.
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>

                <Tabs
                    value={activeTab}
                    onValueChange={(v) => setActiveTab(v as 'manual' | 'excel')}
                    className="mt-2 w-full"
                >
                    <TabsList className="grid w-full grid-cols-2">
                        <TabsTrigger
                            value="manual"
                            className="flex items-center gap-2"
                        >
                            <User className="h-4 w-4" />
                            Manual Entry
                        </TabsTrigger>
                        <TabsTrigger
                            value="excel"
                            className="flex items-center gap-2 text-muted-foreground"
                        >
                            <FileSpreadsheet className="h-4 w-4" />
                            Import Excel
                            <Badge
                                variant="outline"
                                className="ml-1 px-1 py-0 text-[10px] font-normal"
                            >
                                Phase 2
                            </Badge>
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="excel" className="py-6">
                        <div className="space-y-3 rounded-xl border border-dashed border-border bg-muted/20 p-8 text-center">
                            <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
                                <FileSpreadsheet className="h-6 w-6" />
                            </div>
                            <h3 className="text-base font-semibold text-foreground">
                                Excel Import (Coming Soon)
                            </h3>
                            <p className="mx-auto max-w-md text-sm text-muted-foreground">
                                Historical spreadsheet upload with template
                                validation and batch import is planned for Phase
                                2. Please use the <strong>Manual Entry</strong>{' '}
                                tab to record past assignments now.
                            </p>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setActiveTab('manual')}
                                className="mt-2"
                            >
                                Switch to Manual Entry
                            </Button>
                        </div>
                    </TabsContent>

                    <TabsContent value="manual" className="space-y-4 pt-2">
                        {step === 'form' ? (
                            <>
                                {validationError && (
                                    <Alert variant="destructive">
                                        <AlertTriangle className="h-4 w-4" />
                                        <AlertTitle>
                                            Validation Block
                                        </AlertTitle>
                                        <AlertDescription>
                                            {validationError}
                                        </AlertDescription>
                                    </Alert>
                                )}

                                <div className="space-y-4">
                                    {/* Primary Details */}
                                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                        <div className="space-y-1.5">
                                            <Label htmlFor="historical-employee">
                                                Employee{' '}
                                                <span className="text-destructive">
                                                    *
                                                </span>
                                            </Label>
                                            <AppSelect
                                                value={String(
                                                    form.data.employee_id || '',
                                                )}
                                                onValueChange={
                                                    handleEmployeeChange
                                                }
                                                placeholder="Select employee..."
                                                searchPlaceholder="Search employee by name..."
                                            >
                                                <AppSelectItem value="">
                                                    Select employee...
                                                </AppSelectItem>
                                                {formOptions.employees.map(
                                                    (emp) => (
                                                        <AppSelectItem
                                                            key={emp.id}
                                                            value={String(
                                                                emp.id,
                                                            )}
                                                        >
                                                            {emp.name}{' '}
                                                            {emp.employee_no
                                                                ? `(${emp.employee_no})`
                                                                : ''}
                                                        </AppSelectItem>
                                                    ),
                                                )}
                                            </AppSelect>
                                            <InputError
                                                message={
                                                    form.errors.employee_id
                                                }
                                            />
                                        </div>

                                        <div className="space-y-1.5">
                                            <Label htmlFor="historical-vessel">
                                                Vessel{' '}
                                                <span className="text-destructive">
                                                    *
                                                </span>
                                            </Label>
                                            <AppSelect
                                                value={String(
                                                    form.data.vessel_id || '',
                                                )}
                                                onValueChange={
                                                    handleVesselChange
                                                }
                                                placeholder="Select vessel..."
                                                searchPlaceholder="Search vessel..."
                                            >
                                                <AppSelectItem value="">
                                                    Select vessel...
                                                </AppSelectItem>
                                                {formOptions.vessels.map(
                                                    (v) => (
                                                        <AppSelectItem
                                                            key={v.id}
                                                            value={String(v.id)}
                                                        >
                                                            {v.name}
                                                        </AppSelectItem>
                                                    ),
                                                )}
                                            </AppSelect>
                                            <InputError
                                                message={form.errors.vessel_id}
                                            />
                                        </div>

                                        <div className="space-y-1.5">
                                            <Label htmlFor="historical-rank">
                                                Rank{' '}
                                                <span className="text-destructive">
                                                    *
                                                </span>
                                            </Label>
                                            <AppSelect
                                                value={String(
                                                    form.data.rank_id || '',
                                                )}
                                                onValueChange={(val) =>
                                                    form.setData(
                                                        'rank_id',
                                                        val ? Number(val) : '',
                                                    )
                                                }
                                                placeholder="Select rank..."
                                                searchPlaceholder="Search rank..."
                                            >
                                                <AppSelectItem value="">
                                                    Select rank...
                                                </AppSelectItem>
                                                {formOptions.ranks.map((r) => (
                                                    <AppSelectItem
                                                        key={r.id}
                                                        value={String(r.id)}
                                                    >
                                                        {r.name}
                                                    </AppSelectItem>
                                                ))}
                                            </AppSelect>
                                            <InputError
                                                message={form.errors.rank_id}
                                            />
                                        </div>

                                        <div className="space-y-1.5">
                                            <Label htmlFor="historical-client">
                                                Client
                                            </Label>
                                            <AppSelect
                                                value={String(
                                                    form.data.client_id || '',
                                                )}
                                                onValueChange={(val) =>
                                                    form.setData(
                                                        'client_id',
                                                        val ? Number(val) : '',
                                                    )
                                                }
                                                placeholder="Select client (optional)..."
                                                searchPlaceholder="Search client..."
                                            >
                                                <AppSelectItem value="">
                                                    Select client...
                                                </AppSelectItem>
                                                {formOptions.clients.map(
                                                    (c) => (
                                                        <AppSelectItem
                                                            key={c.id}
                                                            value={String(c.id)}
                                                        >
                                                            {c.name}
                                                        </AppSelectItem>
                                                    ),
                                                )}
                                            </AppSelect>
                                            <InputError
                                                message={form.errors.client_id}
                                            />
                                        </div>
                                    </div>

                                    {/* Sea Service Dates */}
                                    <div className="space-y-3 rounded-xl border border-border/70 bg-card p-4">
                                        <div className="flex items-center gap-2 text-sm font-medium text-foreground">
                                            <Ship className="h-4 w-4 text-primary" />
                                            <span>
                                                Onboard Period (P4 Sea Service)
                                            </span>
                                        </div>
                                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                            <div className="space-y-1.5">
                                                <Label htmlFor="historical-joined-vessel">
                                                    Joined Vessel{' '}
                                                    <span className="text-destructive">
                                                        *
                                                    </span>
                                                </Label>
                                                <Input
                                                    id="historical-joined-vessel"
                                                    type="date"
                                                    value={
                                                        form.data
                                                            .joined_vessel_at
                                                    }
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'joined_vessel_at',
                                                            e.target.value,
                                                        )
                                                    }
                                                    required
                                                />
                                                <InputError
                                                    message={
                                                        form.errors
                                                            .joined_vessel_at
                                                    }
                                                />
                                            </div>

                                            <div className="space-y-1.5">
                                                <Label htmlFor="historical-disembarked">
                                                    Disembarked{' '}
                                                    <span className="text-destructive">
                                                        *
                                                    </span>
                                                </Label>
                                                <Input
                                                    id="historical-disembarked"
                                                    type="date"
                                                    value={
                                                        form.data.disembarked_at
                                                    }
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'disembarked_at',
                                                            e.target.value,
                                                        )
                                                    }
                                                    required
                                                />
                                                <InputError
                                                    message={
                                                        form.errors
                                                            .disembarked_at
                                                    }
                                                />
                                            </div>
                                        </div>
                                        <p className="flex items-center gap-1 text-xs text-muted-foreground">
                                            <Clock className="h-3.5 w-3.5" />
                                            Recorded in company time:{' '}
                                            {timezoneLabel}. Dates must be in
                                            the past.
                                        </p>
                                    </div>

                                    {/* Collapsible More Movement Details */}
                                    <div className="overflow-hidden rounded-xl border border-border/60">
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setShowMoreDetails(
                                                    (prev) => !prev,
                                                )
                                            }
                                            className="flex w-full items-center justify-between bg-muted/40 p-3 text-left text-sm font-medium transition-colors hover:bg-muted/60"
                                        >
                                            <span className="flex items-center gap-2">
                                                <span>
                                                    Add More Movement Details
                                                </span>
                                                <span className="text-xs font-normal text-muted-foreground">
                                                    (Mobilisation, Standby,
                                                    Training, Travel)
                                                </span>
                                            </span>
                                            {showMoreDetails ? (
                                                <ChevronUp className="h-4 w-4 text-muted-foreground" />
                                            ) : (
                                                <ChevronDown className="h-4 w-4 text-muted-foreground" />
                                            )}
                                        </button>

                                        {showMoreDetails && (
                                            <div className="space-y-4 border-t border-border/60 bg-card p-4 text-sm">
                                                <p className="text-xs text-muted-foreground">
                                                    Optional historical phases
                                                    will only be recorded if
                                                    explicitly provided. Omitted
                                                    phases are never fabricated.
                                                </p>

                                                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="historical-mobilisation">
                                                            Mobilisation Start
                                                            (P0)
                                                        </Label>
                                                        <Input
                                                            id="historical-mobilisation"
                                                            type="date"
                                                            value={
                                                                form.data
                                                                    .mobilisation_at ??
                                                                ''
                                                            }
                                                            onChange={(e) =>
                                                                form.setData(
                                                                    'mobilisation_at',
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                        />
                                                        <InputError
                                                            message={
                                                                form.errors
                                                                    .mobilisation_at
                                                            }
                                                        />
                                                    </div>

                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="historical-arrival">
                                                            Arrival / Join
                                                            Standby (P1)
                                                        </Label>
                                                        <Input
                                                            id="historical-arrival"
                                                            type="date"
                                                            value={
                                                                form.data
                                                                    .arrival_at ??
                                                                ''
                                                            }
                                                            onChange={(e) =>
                                                                form.setData(
                                                                    'arrival_at',
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                        />
                                                        <InputError
                                                            message={
                                                                form.errors
                                                                    .arrival_at
                                                            }
                                                        />
                                                    </div>

                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="historical-training-start">
                                                            Training Start (P2A
                                                            Start)
                                                        </Label>
                                                        <Input
                                                            id="historical-training-start"
                                                            type="date"
                                                            value={
                                                                form.data
                                                                    .training_started_at ??
                                                                ''
                                                            }
                                                            onChange={(e) =>
                                                                form.setData(
                                                                    'training_started_at',
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                        />
                                                        <InputError
                                                            message={
                                                                form.errors
                                                                    .training_started_at
                                                            }
                                                        />
                                                    </div>

                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="historical-training-end">
                                                            Training End (P2A
                                                            End)
                                                        </Label>
                                                        <Input
                                                            id="historical-training-end"
                                                            type="date"
                                                            value={
                                                                form.data
                                                                    .training_ended_at ??
                                                                ''
                                                            }
                                                            onChange={(e) =>
                                                                form.setData(
                                                                    'training_ended_at',
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                        />
                                                        <InputError
                                                            message={
                                                                form.errors
                                                                    .training_ended_at
                                                            }
                                                        />
                                                    </div>

                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="historical-ready-to-join">
                                                            Ready to Join (P3)
                                                        </Label>
                                                        <Input
                                                            id="historical-ready-to-join"
                                                            type="date"
                                                            value={
                                                                form.data
                                                                    .ready_to_join_at ??
                                                                ''
                                                            }
                                                            onChange={(e) =>
                                                                form.setData(
                                                                    'ready_to_join_at',
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                        />
                                                        <InputError
                                                            message={
                                                                form.errors
                                                                    .ready_to_join_at
                                                            }
                                                        />
                                                    </div>

                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="historical-post-signoff">
                                                            Post Sign-Off
                                                            Standby (P5)
                                                        </Label>
                                                        <Input
                                                            id="historical-post-signoff"
                                                            type="date"
                                                            value={
                                                                form.data
                                                                    .post_signoff_standby_at ??
                                                                ''
                                                            }
                                                            onChange={(e) =>
                                                                form.setData(
                                                                    'post_signoff_standby_at',
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                        />
                                                        <InputError
                                                            message={
                                                                form.errors
                                                                    .post_signoff_standby_at
                                                            }
                                                        />
                                                    </div>

                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="historical-travel-home">
                                                            Travel Home (P6)
                                                        </Label>
                                                        <Input
                                                            id="historical-travel-home"
                                                            type="date"
                                                            value={
                                                                form.data
                                                                    .travel_home_at ??
                                                                ''
                                                            }
                                                            onChange={(e) =>
                                                                form.setData(
                                                                    'travel_home_at',
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                        />
                                                        <InputError
                                                            message={
                                                                form.errors
                                                                    .travel_home_at
                                                            }
                                                        />
                                                    </div>

                                                    <div className="space-y-1.5">
                                                        <Label htmlFor="historical-closed-at">
                                                            Assignment Closed
                                                            Date
                                                        </Label>
                                                        <Input
                                                            id="historical-closed-at"
                                                            type="date"
                                                            value={
                                                                form.data
                                                                    .assignment_closed_at ??
                                                                ''
                                                            }
                                                            onChange={(e) =>
                                                                form.setData(
                                                                    'assignment_closed_at',
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                        />
                                                        <InputError
                                                            message={
                                                                form.errors
                                                                    .assignment_closed_at
                                                            }
                                                        />
                                                    </div>
                                                </div>
                                            </div>
                                        )}
                                    </div>

                                    {/* Remarks */}
                                    <div className="space-y-1.5">
                                        <Label htmlFor="historical-remarks">
                                            Remarks / Historical Notes
                                        </Label>
                                        <Textarea
                                            id="historical-remarks"
                                            rows={2}
                                            placeholder="Add any historical context or reference notes..."
                                            value={form.data.remarks ?? ''}
                                            onChange={(e) =>
                                                form.setData(
                                                    'remarks',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={form.errors.remarks}
                                        />
                                    </div>
                                </div>

                                <DialogFooter className="mt-6 flex items-center justify-between sm:justify-between">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => handleClose(false)}
                                    >
                                        Cancel
                                    </Button>
                                    <Button
                                        type="button"
                                        onClick={handleValidate}
                                        disabled={isValidating}
                                        className="gap-2"
                                    >
                                        {isValidating && (
                                            <Loader2 className="h-4 w-4 animate-spin" />
                                        )}
                                        Validate
                                    </Button>
                                </DialogFooter>
                            </>
                        ) : previewData ? (
                            /* Preview Step */
                            <div className="space-y-5">
                                <div className="flex items-start gap-3 rounded-xl border border-primary/20 bg-primary/5 p-4">
                                    <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0 text-primary" />
                                    <div className="space-y-1">
                                        <h4 className="text-sm font-semibold text-foreground">
                                            Authoritative Validation Succeeded
                                        </h4>
                                        <p className="text-xs text-muted-foreground">
                                            The proposed historical record has
                                            been authoritatively verified
                                            against chronological, tenancy, and
                                            overlap rules. Review the summary
                                            below before persisting.
                                        </p>
                                    </div>
                                </div>

                                {/* Summary Box */}
                                <div className="space-y-3 rounded-xl border border-border bg-card p-4">
                                    <div className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                                        <div>
                                            <span className="block text-xs text-muted-foreground">
                                                Employee
                                            </span>
                                            <span className="font-semibold text-foreground">
                                                {previewData.employee.name}
                                            </span>
                                            {previewData.employee
                                                .employee_no && (
                                                <span className="block text-xs text-muted-foreground">
                                                    #
                                                    {
                                                        previewData.employee
                                                            .employee_no
                                                    }
                                                </span>
                                            )}
                                        </div>
                                        <div>
                                            <span className="block text-xs text-muted-foreground">
                                                Vessel
                                            </span>
                                            <span className="font-semibold text-foreground">
                                                {previewData.vessel.name}
                                            </span>
                                        </div>
                                        <div>
                                            <span className="block text-xs text-muted-foreground">
                                                Rank
                                            </span>
                                            <span className="font-semibold text-foreground">
                                                {previewData.rank.name}
                                            </span>
                                        </div>
                                        <div>
                                            <span className="block text-xs text-muted-foreground">
                                                Client
                                            </span>
                                            <span className="font-semibold text-foreground">
                                                {previewData.client?.name ??
                                                    '—'}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                {/* Timeline Visual */}
                                <div className="space-y-3 rounded-xl border border-border/70 bg-muted/20 p-4">
                                    <h5 className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                        Historical Movement Timeline
                                    </h5>
                                    <div className="relative space-y-4 pl-6 before:absolute before:top-2 before:bottom-2 before:left-2.5 before:w-0.5 before:bg-border">
                                        {previewData.timeline.map(
                                            (item, idx) => (
                                                <div
                                                    key={idx}
                                                    className="relative flex items-start justify-between gap-4"
                                                >
                                                    <div className="absolute top-1 -left-6 h-3 w-3 rounded-full border-2 border-background bg-primary" />
                                                    <div>
                                                        <span className="block text-xs font-semibold text-foreground">
                                                            [{item.phase_code}]{' '}
                                                            {item.phase_label}
                                                        </span>
                                                        <span className="text-xs text-muted-foreground">
                                                            {formatDisplayDate(
                                                                item.start,
                                                            )}
                                                            {item.end
                                                                ? ` → ${formatDisplayDate(item.end)}`
                                                                : ''}
                                                        </span>
                                                    </div>
                                                    {item.duration_days !=
                                                        null && (
                                                        <Badge
                                                            variant="secondary"
                                                            className="text-xs"
                                                        >
                                                            {item.duration_days}{' '}
                                                            {item.duration_days ===
                                                            1
                                                                ? 'day'
                                                                : 'days'}
                                                        </Badge>
                                                    )}
                                                </div>
                                            ),
                                        )}
                                    </div>
                                </div>

                                {/* Checks Checklist */}
                                <div className="space-y-2.5 rounded-xl border border-border/60 bg-card p-4">
                                    <h5 className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                        Authoritative Verification Checks
                                    </h5>
                                    <div className="grid grid-cols-1 gap-2 text-xs sm:grid-cols-2">
                                        {previewData.checks.map(
                                            (check, idx) => (
                                                <div
                                                    key={idx}
                                                    className="flex items-center gap-2"
                                                >
                                                    <CheckCircle2 className="h-4 w-4 shrink-0 text-emerald-500" />
                                                    <span className="text-muted-foreground">
                                                        {check.message}
                                                    </span>
                                                </div>
                                            ),
                                        )}
                                    </div>
                                </div>

                                {/* Sea Service Impact */}
                                <div className="flex items-start gap-3 rounded-xl border border-emerald-500/20 bg-emerald-500/5 p-4">
                                    <Ship className="mt-0.5 h-5 w-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                    <div className="space-y-1 text-sm">
                                        <h5 className="font-semibold text-foreground">
                                            Sea Service Impact
                                        </h5>
                                        <p className="text-xs text-muted-foreground">
                                            {previewData.sea_service.message}
                                        </p>
                                    </div>
                                </div>

                                {previewData.warnings.length > 0 && (
                                    <div className="space-y-1 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4">
                                        <div className="flex items-center gap-2 text-sm font-semibold text-amber-600 dark:text-amber-400">
                                            <AlertTriangle className="h-4 w-4" />
                                            Warnings
                                        </div>
                                        <ul className="list-inside list-disc space-y-0.5 text-xs text-muted-foreground">
                                            {previewData.warnings.map(
                                                (w, idx) => (
                                                    <li key={idx}>{w}</li>
                                                ),
                                            )}
                                        </ul>
                                    </div>
                                )}

                                <DialogFooter className="mt-6 flex items-center justify-between sm:justify-between">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setStep('form')}
                                        disabled={form.processing}
                                        className="gap-2"
                                    >
                                        <ArrowLeft className="h-4 w-4" />
                                        Back
                                    </Button>
                                    <Button
                                        type="button"
                                        onClick={handleConfirmSubmit}
                                        disabled={form.processing}
                                        className="gap-2"
                                    >
                                        {form.processing && (
                                            <Loader2 className="h-4 w-4 animate-spin" />
                                        )}
                                        Add Historical Assignment
                                    </Button>
                                </DialogFooter>
                            </div>
                        ) : null}
                    </TabsContent>
                </Tabs>
            </DialogContent>
        </Dialog>
    );
}
