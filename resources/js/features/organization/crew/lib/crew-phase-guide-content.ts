import type { CrewPhaseCode } from './crew-phase-descriptions.ts';
import { crewPhaseCopy } from './crew-phase-descriptions.ts';

export type CrewPhaseGuideItem = {
    code: CrewPhaseCode;
    label: string;
    description: string;
    compact: string;
    typicalActivity: string;
    usuallyNext: string;
    alternativePath?: string;
    compatibilityNote?: string;
};

const CREW_PHASE_GUIDE: Record<
    CrewPhaseCode,
    Omit<CrewPhaseGuideItem, 'code' | 'label' | 'description' | 'compact'>
> = {
    p0: {
        typicalActivity:
            'Prepare mobilisation, confirm vessel / position / dates, and readiness checks.',
        usuallyNext:
            'Start / continue mobilisation, then Record Arrival when appropriate.',
    },
    p1a: {
        typicalActivity:
            'Hotel / standby while waiting to join; destination may still change before boarding.',
        usuallyNext: 'Join Vessel',
        alternativePath:
            'Send to Training. Changing vessel here updates the current mobilisation — not Transfer Vessel.',
    },
    p1b: {
        typicalActivity: 'Course or operational training during mobilisation.',
        usuallyNext: 'Complete Training, then continue toward vessel joining.',
    },
    p2: {
        typicalActivity:
            'Active sea service, tour monitoring, sign-off planning, and relief planning.',
        usuallyNext:
            'Confirm Disembarkation when the crew member actually leaves.',
        alternativePath:
            'Plan Sign-Off (forecast only) or Transfer Vessel for direct vessel-to-vessel movement.',
    },
    p3: {
        typicalActivity:
            'Post-sign-off hotel / standby while awaiting return home or next mobilisation.',
        usuallyNext: 'Return Home',
        alternativePath:
            'Redeploy if going directly into another mobilisation.',
    },
    p4: {
        typicalActivity:
            'Home or final redeployment stage of the current cycle.',
        usuallyNext: 'Close Assignment',
        alternativePath: 'Redeploy into a new linked mobilisation.',
    },
};

export const CREW_PHASE_GUIDE_FLOW: CrewPhaseCode[] = [
    'p0',
    'p1a',
    'p1b',
    'p2',
    'p3',
    'p4',
];

/** Retired phases are not part of the modern guide flow. */
export const CREW_PHASE_GUIDE_COMPATIBILITY: CrewPhaseCode[] = [];

export function crewPhaseGuideItems(): CrewPhaseGuideItem[] {
    return (Object.keys(CREW_PHASE_GUIDE) as CrewPhaseCode[]).map((code) => {
        const copy = crewPhaseCopy(code);

        return {
            code,
            label: copy?.label ?? code.toUpperCase(),
            description: copy?.description ?? '',
            compact: copy?.compact ?? '',
            ...CREW_PHASE_GUIDE[code],
        };
    });
}

export function crewPhaseGuideFlowItems(): CrewPhaseGuideItem[] {
    return CREW_PHASE_GUIDE_FLOW.map(
        (code) => crewPhaseGuideItems().find((item) => item.code === code)!,
    );
}

export function crewPhaseGuideCompatibilityItems(): CrewPhaseGuideItem[] {
    return CREW_PHASE_GUIDE_COMPATIBILITY.map(
        (code) => crewPhaseGuideItems().find((item) => item.code === code)!,
    );
}

export const CREW_PHASE_GUIDE_FOOTNOTE =
    'Not every assignment uses every phase. Actual movement is determined by recorded Crew Assignment phases.';
