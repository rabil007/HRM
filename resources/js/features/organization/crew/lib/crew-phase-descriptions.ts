export const CREW_PHASE_CODES = ['p0', 'p1a', 'p1b', 'p2', 'p3', 'p4'] as const;

export type CrewPhaseCode = (typeof CREW_PHASE_CODES)[number];

/**
 * Exclusive retired stored codes — display only for historical audit rows.
 * Modern p3 (Demobilisation Standby) and p4 (Home / Redeployment) are never legacy.
 */
export const LEGACY_CREW_PHASE_CODES = [
    'p1',
    'p2a',
    'p2b',
    'p5',
    'p6',
] as const;

export type LegacyCrewPhaseCode = (typeof LEGACY_CREW_PHASE_CODES)[number];

export type CrewPhaseCopy = {
    code: CrewPhaseCode | LegacyCrewPhaseCode;
    label: string;
    description: string;
    compact: string;
};

const CREW_PHASE_COPY = {
    p0: {
        label: 'Pre-Arrival',
        description:
            'Assignment prepared and waiting for the crew member to arrive.',
        compact: 'Waiting for arrival',
    },
    p1a: {
        label: 'Pre-Joining Standby',
        description:
            'Waiting or staying in hotel/accommodation before joining the vessel.',
        compact: 'Pre-joining standby',
    },
    p1b: {
        label: 'Training',
        description: 'Completing required training before joining.',
        compact: 'Training',
    },
    p2: {
        label: 'On Vessel',
        description: 'Currently onboard the vessel.',
        compact: 'On vessel',
    },
    p3: {
        label: 'Demobilisation Standby',
        description:
            'Disembarked and waiting or staying in hotel/accommodation for onward or home travel.',
        compact: 'Demob standby',
    },
    p4: {
        label: 'Home / Redeployment',
        description: 'Returned home or moving toward the next assignment.',
        compact: 'Home / redeploy',
    },
    p1: {
        label: 'Travel In (legacy)',
        description: 'Retired lifecycle phase.',
        compact: 'Legacy travel in',
    },
    p2a: {
        label: 'Pre-Joining Standby (legacy code)',
        description: 'Former P2A code; use P1A.',
        compact: 'Legacy P2A',
    },
    p2b: {
        label: 'Training (legacy code)',
        description: 'Former P2B code; use P1B.',
        compact: 'Legacy P2B',
    },
    p5: {
        label: 'Demobilisation Standby (legacy code)',
        description: 'Former P5 code; use P3.',
        compact: 'Legacy P5',
    },
    p6: {
        label: 'Home / Redeployment (legacy code)',
        description: 'Former P6 code; use P4.',
        compact: 'Legacy P6',
    },
} as const satisfies Record<
    CrewPhaseCode | LegacyCrewPhaseCode,
    Omit<CrewPhaseCopy, 'code'>
>;

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

    if (!(key in CREW_PHASE_COPY)) {
        return null;
    }

    return {
        code: key as CrewPhaseCopy['code'],
        ...CREW_PHASE_COPY[key as keyof typeof CREW_PHASE_COPY],
    };
}

export function crewPhaseDescription(
    code: string | null | undefined,
): string | null {
    return crewPhaseCopy(code)?.description ?? null;
}

export { crewPhaseGuideItems } from './crew-phase-guide-content.ts';
