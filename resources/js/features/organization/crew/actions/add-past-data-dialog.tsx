import { useForm, useHttp } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    FileSpreadsheet,
    History,
    Link as LinkIcon,
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
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import {
    formatCompanyTimezoneLabel,
    useCompanyTimezone,
} from '@/lib/company-timezone';
import { mapHistoricalValidationErrors } from '../lib/historical-validation-errors';
import type {
    HistoricalCrewAssignmentFormData,
    HistoricalCrewAssignmentPreviewData,
    HistoricalFormOptions,
} from '../types';
import { HistoricalImportExcelPanel } from './historical-import-excel-panel';
import { PastCrewPeriodFields } from './past-crew-period-fields';

interface AddPastDataDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    formOptions: HistoricalFormOptions;
}

const MOVEMENT_DATE_KEYS = [
    'sign_on_standby_from',
    'onsite_from',
    'sign_off_standby_from',
    'home_available_from',
] as const;

function seaServiceStatusLabel(status: string): string {
    if (status === 'will_link') {
        return 'Will Link Existing';
    }

    if (status === 'will_create') {
        return 'Will Create Record';
    }

    if (status === 'will_create_ongoing') {
        return 'Will Create Ongoing';
    }

    if (status === 'not_applicable') {
        return 'Not Applicable';
    }

    return status;
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
    const [previewData, setPreviewData] =
        useState<HistoricalCrewAssignmentPreviewData | null>(null);
    const [isValidating, setIsValidating] = useState(false);
    const [validationError, setValidationError] = useState<string | null>(null);

    const form = useForm<HistoricalCrewAssignmentFormData>({
        employee_id: '',
        vessel_id: '',
        rank_id: '',
        client_id: '',
        sign_on_standby_from: '',
        sign_on_standby_to: '',
        onsite_from: '',
        onsite_to: '',
        sign_off_standby_from: '',
        sign_off_standby_to: '',
        home_available_from: '',
        sign_on_accommodation: 'not_recorded',
        sign_on_hotel_id: '',
        sign_on_room_type_id: '',
        sign_on_hotel_check_in: '',
        sign_on_hotel_check_out: '',
        sign_off_accommodation: 'not_recorded',
        sign_off_hotel_id: '',
        sign_off_room_type_id: '',
        sign_off_hotel_check_in: '',
        sign_off_hotel_check_out: '',
        remarks: '',
    });

    const http = useHttp();

    const selectedEmployee = formOptions.employees.find(
        (employee) => employee.id === Number(form.data.employee_id),
    );
    const employeeCurrentRankName =
        selectedEmployee?.rank_id != null
            ? (formOptions.ranks.find(
                  (rank) => rank.id === selectedEmployee.rank_id,
              )?.name ?? null)
            : null;

    const handleClose = (nextOpen: boolean) => {
        if (!nextOpen) {
            form.reset();
            form.clearErrors();
            setStep('form');
            setPreviewData(null);
            setValidationError(null);
        }

        onOpenChange(nextOpen);
    };

    const handleEmployeeChange = (employeeIdStr: string) => {
        const empId = employeeIdStr ? Number(employeeIdStr) : '';
        form.setData((prev) => {
            const previousId =
                prev.employee_id === '' || prev.employee_id === null
                    ? ''
                    : Number(prev.employee_id);
            const nextId = empId === '' ? '' : Number(empId);
            const employeeChanged = previousId !== nextId;

            return {
                ...prev,
                employee_id: empId,
                rank_id: employeeChanged ? '' : prev.rank_id,
            };
        });
    };

    const handleVesselChange = (vesselIdStr: string) => {
        const vId = vesselIdStr ? Number(vesselIdStr) : '';
        form.setData((prev) => ({
            ...prev,
            vessel_id: vId,
        }));
    };

    const handleValidate = async () => {
        form.clearErrors();
        setValidationError(null);

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

        const hasMovementDate = MOVEMENT_DATE_KEYS.some((key) => {
            const value = form.data[key];

            return typeof value === 'string' && value.trim() !== '';
        });

        if (!hasMovementDate) {
            setValidationError(
                'Enter at least one movement period (Sign-On, Onsite, Sign-Off, or Home).',
            );
        }

        if (Object.keys(localErrors).length > 0 || !hasMovementDate) {
            if (Object.keys(localErrors).length > 0) {
                form.setError(localErrors);
            }

            return;
        }

        setIsValidating(true);

        const payload = {
            employee_id: Number(form.data.employee_id),
            vessel_id: Number(form.data.vessel_id),
            rank_id: Number(form.data.rank_id),
            client_id: form.data.client_id ? Number(form.data.client_id) : null,
            sign_on_standby_from: form.data.sign_on_standby_from || null,
            sign_on_standby_to: form.data.sign_on_standby_to || null,
            onsite_from: form.data.onsite_from || null,
            onsite_to: form.data.onsite_to || null,
            sign_off_standby_from: form.data.sign_off_standby_from || null,
            sign_off_standby_to: form.data.sign_off_standby_to || null,
            home_available_from: form.data.home_available_from || null,
            sign_on_accommodation:
                form.data.sign_on_accommodation || 'not_recorded',
            sign_on_hotel_id: form.data.sign_on_hotel_id
                ? Number(form.data.sign_on_hotel_id)
                : null,
            sign_on_room_type_id: form.data.sign_on_room_type_id
                ? Number(form.data.sign_on_room_type_id)
                : null,
            sign_on_hotel_check_in: form.data.sign_on_hotel_check_in || null,
            sign_on_hotel_check_out: form.data.sign_on_hotel_check_out || null,
            sign_off_accommodation:
                form.data.sign_off_accommodation || 'not_recorded',
            sign_off_hotel_id: form.data.sign_off_hotel_id
                ? Number(form.data.sign_off_hotel_id)
                : null,
            sign_off_room_type_id: form.data.sign_off_room_type_id
                ? Number(form.data.sign_off_room_type_id)
                : null,
            sign_off_hotel_check_in: form.data.sign_off_hotel_check_in || null,
            sign_off_hotel_check_out:
                form.data.sign_off_hotel_check_out || null,
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
                        errors?: Record<string, string | string[]>;
                        message?: string;
                    };
                };
            };
            const errors = errorObj?.response?.data?.errors;

            if (errors) {
                const { fieldErrors, alertMessage } =
                    mapHistoricalValidationErrors(errors);

                form.setError(fieldErrors);

                if (alertMessage) {
                    setValidationError(alertMessage);
                } else if (errorObj?.response?.data?.message) {
                    setValidationError(errorObj.response.data.message);
                }
            } else {
                setValidationError(
                    errorObj?.response?.data?.message ??
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

                const { fieldErrors, alertMessage } =
                    mapHistoricalValidationErrors(
                        errs as Record<string, string | string[]>,
                    );

                form.setError(fieldErrors);

                if (alertMessage) {
                    setValidationError(alertMessage);
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
                                Reconstruct crew movement history and bootstrap
                                the employee&apos;s inferred current state from
                                known dates.
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
                            className="flex items-center gap-2"
                        >
                            <FileSpreadsheet className="h-4 w-4" />
                            Import Excel
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="excel" className="py-2">
                        <HistoricalImportExcelPanel />
                    </TabsContent>

                    <TabsContent value="manual" className="space-y-4 pt-2">
                        {step === 'form' ? (
                            <>
                                {validationError && (
                                    <Alert variant="destructive">
                                        <AlertTriangle className="h-4 w-4" />
                                        <AlertTitle>
                                            Historical record cannot be added
                                        </AlertTitle>
                                        <AlertDescription>
                                            {validationError}
                                        </AlertDescription>
                                    </Alert>
                                )}

                                <div className="space-y-4">
                                    <div className="space-y-3 rounded-xl border border-border/80 bg-muted/20 p-4">
                                        <div className="text-sm font-semibold text-foreground">
                                            Crew / Assignment Details
                                        </div>
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
                                                        form.data.employee_id ||
                                                            '',
                                                    )}
                                                    onValueChange={
                                                        handleEmployeeChange
                                                    }
                                                    placeholder="Select employee..."
                                                    searchPlaceholder="Search employee by name or number..."
                                                >
                                                    <AppSelectItem value="">
                                                        Select employee...
                                                    </AppSelectItem>
                                                    {formOptions.employees.map(
                                                        (emp) => {
                                                            const isNonActive =
                                                                emp.status &&
                                                                emp.status !==
                                                                    'active';
                                                            const statusLabel =
                                                                isNonActive
                                                                    ? ` — ${emp.status
                                                                          .replace(
                                                                              /_/g,
                                                                              ' ',
                                                                          )
                                                                          .toUpperCase()}`
                                                                    : '';

                                                            return (
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
                                                                    {
                                                                        statusLabel
                                                                    }
                                                                </AppSelectItem>
                                                            );
                                                        },
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
                                                        form.data.vessel_id ||
                                                            '',
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
                                                                value={String(
                                                                    v.id,
                                                                )}
                                                            >
                                                                {v.name}
                                                            </AppSelectItem>
                                                        ),
                                                    )}
                                                </AppSelect>
                                                <InputError
                                                    message={
                                                        form.errors.vessel_id
                                                    }
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
                                                            val
                                                                ? Number(val)
                                                                : '',
                                                        )
                                                    }
                                                    placeholder="Select rank..."
                                                    searchPlaceholder="Search rank..."
                                                >
                                                    <AppSelectItem value="">
                                                        Select rank...
                                                    </AppSelectItem>
                                                    {formOptions.ranks.map(
                                                        (r) => (
                                                            <AppSelectItem
                                                                key={r.id}
                                                                value={String(
                                                                    r.id,
                                                                )}
                                                            >
                                                                {r.name}
                                                            </AppSelectItem>
                                                        ),
                                                    )}
                                                </AppSelect>
                                                {employeeCurrentRankName ? (
                                                    <p className="text-xs text-muted-foreground">
                                                        Employee&apos;s current
                                                        rank:{' '}
                                                        {
                                                            employeeCurrentRankName
                                                        }{' '}
                                                        (select explicitly for
                                                        this historical record)
                                                    </p>
                                                ) : null}
                                                <InputError
                                                    message={
                                                        form.errors.rank_id
                                                    }
                                                />
                                            </div>

                                            <div className="space-y-1.5">
                                                <Label htmlFor="historical-client">
                                                    Client (Optional)
                                                </Label>
                                                <AppSelect
                                                    value={String(
                                                        form.data.client_id ||
                                                            '',
                                                    )}
                                                    onValueChange={(val) =>
                                                        form.setData(
                                                            'client_id',
                                                            val
                                                                ? Number(val)
                                                                : '',
                                                        )
                                                    }
                                                    placeholder="Select historical client..."
                                                    searchPlaceholder="Search client..."
                                                >
                                                    <AppSelectItem value="">
                                                        None
                                                    </AppSelectItem>
                                                    {formOptions.clients.map(
                                                        (c) => (
                                                            <AppSelectItem
                                                                key={c.id}
                                                                value={String(
                                                                    c.id,
                                                                )}
                                                            >
                                                                {c.name}
                                                            </AppSelectItem>
                                                        ),
                                                    )}
                                                </AppSelect>
                                                <p className="text-xs text-muted-foreground">
                                                    May differ from the
                                                    vessel&apos;s current
                                                    client.
                                                </p>
                                                <InputError
                                                    message={
                                                        form.errors.client_id
                                                    }
                                                />
                                            </div>
                                        </div>
                                    </div>

                                    <PastCrewPeriodFields
                                        form={form}
                                        formOptions={formOptions}
                                        timezoneLabel={timezoneLabel}
                                    />

                                    <div className="space-y-1.5">
                                        <Label htmlFor="historical-remarks">
                                            Remarks
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
                            <div className="space-y-5">
                                <div className="flex items-start gap-3 rounded-xl border border-primary/20 bg-primary/5 p-4">
                                    <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0 text-primary" />
                                    <div className="space-y-1">
                                        <h4 className="text-sm font-semibold text-foreground">
                                            Ready to save
                                        </h4>
                                        <p className="text-xs text-muted-foreground">
                                            Review the known periods and
                                            inferred current state before saving
                                            past crew data.
                                        </p>
                                    </div>
                                </div>

                                {previewData.inferred_state ? (
                                    <div className="space-y-2 rounded-xl border border-sky-500/30 bg-sky-500/10 p-4">
                                        <p className="text-xs font-semibold tracking-wider text-sky-700 uppercase dark:text-sky-300">
                                            Current State —{' '}
                                            {previewData.inferred_state.label}
                                        </p>
                                        <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                            <Badge
                                                variant="secondary"
                                                className="text-[10px]"
                                            >
                                                {previewData.inferred_state
                                                    .is_open
                                                    ? 'Open'
                                                    : 'Closed'}
                                            </Badge>
                                            <span>
                                                {
                                                    previewData.inferred_state
                                                        .assignment_status
                                                }
                                            </span>
                                            {previewData.last_movement
                                                ?.display ? (
                                                <span>
                                                    · Last movement:{' '}
                                                    {
                                                        previewData
                                                            .last_movement
                                                            .display
                                                    }
                                                </span>
                                            ) : null}
                                        </div>
                                    </div>
                                ) : null}

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
                                    <div className="border-t border-border/50 pt-2 text-xs text-muted-foreground">
                                        {previewData.summary.sea_service_days !=
                                        null ? (
                                            <span>
                                                Sea Service Duration:{' '}
                                                <strong className="text-foreground">
                                                    {
                                                        previewData.summary
                                                            .sea_service_days
                                                    }{' '}
                                                    days
                                                </strong>
                                                {previewData.summary
                                                    .joined_vessel_at ||
                                                previewData.summary
                                                    .disembarked_at ? (
                                                    <>
                                                        {' '}
                                                        (
                                                        {previewData.summary
                                                            .joined_vessel_at ??
                                                            '—'}{' '}
                                                        →{' '}
                                                        {previewData.summary
                                                            .disembarked_at ??
                                                            '—'}
                                                        )
                                                    </>
                                                ) : null}
                                            </span>
                                        ) : (
                                            <span>
                                                Sea service period not completed
                                                from the provided dates.
                                            </span>
                                        )}
                                        {previewData.summary.remarks && (
                                            <p className="mt-1 italic">
                                                &quot;
                                                {previewData.summary.remarks}
                                                &quot;
                                            </p>
                                        )}
                                    </div>
                                </div>

                                <div className="space-y-3 rounded-xl border border-border/70 bg-muted/20 p-4">
                                    <h5 className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                        Known Periods
                                    </h5>
                                    <div className="space-y-2">
                                        {(
                                            previewData.summary.known_periods ??
                                            previewData.timeline.map(
                                                (item) => ({
                                                    key: item.phase_code,
                                                    label: item.phase_label,
                                                    from: item.start,
                                                    to: item.end,
                                                    to_display:
                                                        item.end_display ??
                                                        (item.is_open
                                                            ? 'Current'
                                                            : (item.end ??
                                                              '—')),
                                                    days: item.duration_days,
                                                    is_open: item.is_open,
                                                }),
                                            )
                                        ).map((period) => (
                                            <div
                                                key={period.key}
                                                className="flex items-center justify-between gap-3 text-sm"
                                            >
                                                <span className="font-medium text-foreground">
                                                    {period.label}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {period.from} →{' '}
                                                    {period.to_display}
                                                    {period.days != null
                                                        ? ` (${period.days}d)`
                                                        : ''}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                    {(previewData.summary.accommodation ?? [])
                                        .length > 0 ? (
                                        <div className="space-y-1 border-t border-border/50 pt-3">
                                            <h6 className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                                Accommodation
                                            </h6>
                                            {previewData.summary.accommodation?.map(
                                                (item) => (
                                                    <p
                                                        key={`${item.label}-${item.detail}`}
                                                        className="text-xs text-muted-foreground"
                                                    >
                                                        {item.label} —{' '}
                                                        {item.detail}
                                                    </p>
                                                ),
                                            )}
                                        </div>
                                    ) : null}
                                </div>

                                {previewData.sea_service.status !==
                                'not_applicable' ? (
                                    <div
                                        className={`flex items-start gap-3 rounded-xl border p-4 ${
                                            previewData.sea_service.status ===
                                            'will_link'
                                                ? 'border-sky-500/20 bg-sky-500/5'
                                                : previewData.sea_service
                                                        .status ===
                                                        'will_create' ||
                                                    previewData.sea_service
                                                        .status ===
                                                        'will_create_ongoing'
                                                  ? 'border-emerald-500/20 bg-emerald-500/5'
                                                  : 'border-amber-500/20 bg-amber-500/5'
                                        }`}
                                    >
                                        {previewData.sea_service.status ===
                                        'will_link' ? (
                                            <LinkIcon className="mt-0.5 h-5 w-5 shrink-0 text-sky-600 dark:text-sky-400" />
                                        ) : (
                                            <Ship className="mt-0.5 h-5 w-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                        )}
                                        <div className="space-y-1 text-sm">
                                            <div className="flex items-center gap-2">
                                                <h5 className="font-semibold text-foreground">
                                                    Sea Service Impact
                                                </h5>
                                                <Badge
                                                    variant={
                                                        previewData.sea_service
                                                            .status ===
                                                        'will_link'
                                                            ? 'secondary'
                                                            : 'default'
                                                    }
                                                    className="text-[10px]"
                                                >
                                                    {seaServiceStatusLabel(
                                                        previewData.sea_service
                                                            .status,
                                                    )}
                                                </Badge>
                                            </div>
                                            <p className="text-xs text-muted-foreground">
                                                {
                                                    previewData.sea_service
                                                        .message
                                                }
                                            </p>
                                        </div>
                                    </div>
                                ) : null}

                                {previewData.warnings.length > 0 ? (
                                    <div className="space-y-1 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4">
                                        <div className="flex items-center gap-2 text-sm font-semibold text-amber-600 dark:text-amber-400">
                                            <AlertTriangle className="h-4 w-4" />
                                            Warnings
                                        </div>
                                        <ul className="list-inside list-disc space-y-0.5 text-xs text-muted-foreground">
                                            {previewData.warnings.map(
                                                (warning, idx) => (
                                                    <li key={idx}>{warning}</li>
                                                ),
                                            )}
                                        </ul>
                                    </div>
                                ) : null}

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
                                        Save Past Crew Data
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
