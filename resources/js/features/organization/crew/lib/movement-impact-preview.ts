import type { ActionImpactPreviewProps } from '../../../../components/action-impact-preview.ts';
import { formatDisplayDateTime12h } from '../../../../lib/format-date.ts';
import type { MovementActionConfig } from '../actions/movement-action-config.ts';
import type {
    CrewAssignmentFormOptions,
    CrewMovementAction,
    CrewMovementActionFormData,
    CrewMovementContext,
} from '../types.ts';
import { CREW_PHASE_LABELS } from '../types.ts';

function phaseLine(
    code: string | null | undefined,
    label: string | null | undefined,
): string | undefined {
    if (!code && !label) {
        return undefined;
    }

    if (code && label) {
        return `${code.toUpperCase()} · ${label}`;
    }

    return label ?? code?.toUpperCase();
}

function resolveVesselName(
    formOptions: CrewAssignmentFormOptions | undefined,
    vesselId: number | null,
): string | undefined {
    if (!vesselId) {
        return undefined;
    }

    return formOptions?.vessels.find((vessel) => vessel.id === vesselId)?.name;
}

function normalizeImpacts(
    description: MovementActionConfig['impactDescription'],
): string[] {
    if (Array.isArray(description)) {
        return description;
    }

    return description ? [description] : [];
}

function formatMovementTime(value: string | null | undefined): string | null {
    if (!value?.trim()) {
        return null;
    }

    return formatDisplayDateTime12h(value);
}

export function buildMovementImpactPreview({
    action,
    config,
    context,
    formData,
    formOptions,
}: {
    action: CrewMovementAction;
    config: MovementActionConfig;
    context: CrewMovementContext;
    formData: CrewMovementActionFormData;
    formOptions?: CrewAssignmentFormOptions;
}): ActionImpactPreviewProps | null {
    if (config.impactPreview === 'none') {
        return null;
    }

    const impacts = normalizeImpacts(config.impactDescription);
    const movementTime = formatMovementTime(formData.occurred_at);
    const currentPhase = phaseLine(
        context.current_phase_code,
        context.current_phase_label,
    );
    const employeeSubject = [context.employee_name, context.employee_no]
        .filter(Boolean)
        .join(' · ');

    const base: ActionImpactPreviewProps = {
        title: config.impactTitle,
        impacts,
        severity: config.impactSeverity ?? 'normal',
        compact: config.impactPreview === 'light',
        movementTime,
    };

    switch (action) {
        case 'transfer_vessel':
            return {
                ...base,
                subject: employeeSubject || undefined,
                currentState: currentPhase
                    ? `${currentPhase}${context.vessel_name ? ` · ${context.vessel_name}` : ''}`
                    : (context.vessel_name ?? undefined),
                destinationState: resolveVesselName(
                    formOptions,
                    formData.vessel_id,
                ),
            };
        case 'redeploy':
            return {
                ...base,
                subject: context.assignment_no,
                currentState: currentPhase ?? undefined,
                destinationState: resolveVesselName(
                    formOptions,
                    formData.vessel_id,
                ),
            };
        case 'confirm_disembarkation':
            return {
                ...base,
                subject: employeeSubject || undefined,
                currentState: currentPhase ?? undefined,
                warning:
                    'Planning a Sign-Off does not disembark the crew member.',
            };
        case 'join_vessel':
            return {
                ...base,
                subject: employeeSubject || undefined,
                currentState: resolveVesselName(
                    formOptions,
                    formData.vessel_id,
                ),
                impacts: [`This will start: P4 · ${CREW_PHASE_LABELS.p4}`],
            };
        case 'approve_mobilisation':
            return {
                ...base,
                subject: employeeSubject || undefined,
                impacts: [`This will start: P0 · ${CREW_PHASE_LABELS.p0}`],
            };
        case 'record_arrival':
        case 'send_to_training':
        case 'complete_training':
        case 'mark_ready':
        case 'start_join_standby':
        case 'start_demob_standby':
            return {
                ...base,
                subject: employeeSubject || undefined,
                currentState: currentPhase ?? undefined,
            };
        case 'travel_home':
        case 'close_assignment':
        case 'cancel_assignment':
            return {
                ...base,
                subject:
                    action === 'cancel_assignment'
                        ? [context.assignment_no, employeeSubject]
                              .filter(Boolean)
                              .join('\n')
                        : employeeSubject || context.assignment_no,
                currentState: currentPhase ?? undefined,
            };
        default:
            return (base.impacts?.length ?? 0) > 0 ||
                base.warning ||
                base.subject ||
                base.currentState
                ? base
                : null;
    }
}
