export const CREW_PHASE_CODES = [
    'p0',
    'p1',
    'p2a',
    'p2b',
    'p3',
    'p4',
    'p5',
    'p6',
] as const;

export type CrewPhaseCode = (typeof CREW_PHASE_CODES)[number];

export type CrewPhaseCopy = {
    code: CrewPhaseCode;
    label: string;
    description: string;
    compact: string;
};

const CREW_PHASE_COPY = {
    p0: {
        label: 'Pre-Mobilisation',
        description:
            'Assignment prepared and waiting for the crew member to arrive.',
        compact: 'Waiting for arrival',
    },
    p1: {
        label: 'Travel In',
        description: 'Travelling to the joining location.',
        compact: 'Travelling to join',
    },
    p2a: {
        label: 'Join Standby',
        description:
            'Waiting or staying in hotel/accommodation before joining the vessel.',
        compact: 'Waiting/hotel before joining',
    },
    p2b: {
        label: 'Training',
        description: 'Completing required training before joining.',
        compact: 'Training',
    },
    p3: {
        label: 'Ready to Join',
        description: 'Cleared and ready to board the vessel.',
        compact: 'Ready to board',
    },
    p4: {
        label: 'On Vessel',
        description: 'Currently onboard the vessel.',
        compact: 'On vessel',
    },
    p5: {
        label: 'Demobilisation Standby',
        description:
            'Disembarked and waiting or staying in hotel/accommodation for onward or home travel.',
        compact: 'Waiting/hotel after disembarkation',
    },
    p6: {
        label: 'Home / Redeployment',
        description: 'Returned home or moving toward the next assignment.',
        compact: 'Home / redeployment',
    },
} as const satisfies Record<CrewPhaseCode, Omit<CrewPhaseCopy, 'code'>>;

export function isCrewPhaseCode(
    value: string | null | undefined,
): value is CrewPhaseCode {
    return (
        typeof value === 'string' &&
        CREW_PHASE_CODES.includes(value.toLowerCase() as CrewPhaseCode)
    );
}

export function crewPhaseCopy(
    code: string | null | undefined,
): CrewPhaseCopy | null {
    if (typeof code !== 'string' || code === '') {
        return null;
    }

    const key = code.toLowerCase();

    if (!isCrewPhaseCode(key)) {
        return null;
    }

    return {
        code: key,
        ...CREW_PHASE_COPY[key],
    };
}

export function crewPhaseDescription(
    code: string | null | undefined,
): string | null {
    return crewPhaseCopy(code)?.description ?? null;
}

export { crewPhaseGuideItems } from './crew-phase-guide-content.ts';
