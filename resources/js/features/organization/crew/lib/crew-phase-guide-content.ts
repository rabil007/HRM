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
            'Prepare mobilisation, confirm vessel / rank / dates, and readiness checks.',
        usuallyNext:
            'Start / continue mobilisation, then Record Arrival when appropriate.',
    },
    p1: {
        typicalActivity: 'Travel toward the mobilisation point.',
        usuallyNext: 'Record Arrival',
        compatibilityNote:
            'Legacy compatibility phase. Normal web workflows do not create new P1 assignments.',
    },
    p2a: {
        typicalActivity:
            'Hotel / standby while waiting to join; destination may still change before boarding.',
        usuallyNext: 'Join Vessel',
        alternativePath:
            'Send to Training. Changing vessel here updates the current mobilisation — not Transfer Vessel.',
    },
    p2b: {
        typicalActivity: 'Course or operational training during mobilisation.',
        usuallyNext: 'Complete Training, then continue toward vessel joining.',
    },
    p3: {
        typicalActivity: 'Final ready-to-board state.',
        usuallyNext: 'Join Vessel',
        compatibilityNote:
            'Legacy compatibility phase. Not every modern workflow creates P3.',
    },
    p4: {
        typicalActivity:
            'Active sea service, tour monitoring, sign-off planning, and relief planning.',
        usuallyNext:
            'Confirm Disembarkation when the crew member actually leaves.',
        alternativePath:
            'Plan Sign-Off (forecast only) or Transfer Vessel for direct vessel-to-vessel movement.',
    },
    p5: {
        typicalActivity:
            'Post-sign-off hotel / standby while awaiting return home or next mobilisation.',
        usuallyNext: 'Return Home',
        alternativePath:
            'Redeploy if going directly into another mobilisation.',
    },
    p6: {
        typicalActivity:
            'Home or final redeployment stage of the current cycle.',
        usuallyNext: 'Close Assignment',
        alternativePath: 'Redeploy into a new linked mobilisation.',
    },
};

export const CREW_PHASE_GUIDE_FLOW: CrewPhaseCode[] = [
    'p0',
    'p2a',
    'p2b',
    'p4',
    'p5',
    'p6',
];

export const CREW_PHASE_GUIDE_COMPATIBILITY: CrewPhaseCode[] = ['p1', 'p3'];

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
