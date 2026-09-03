import type {
    SignaturePlacementConfig,
    SignaturePlacementItem,
} from '../types';

export const SUBJECT_SLOT = 'subject';
export const MAX_ROLE_OCCURRENCE = 7;
export const DEFAULT_SIGNATURE_WIDTH = 0.25;
export const DEFAULT_SIGNATURE_HEIGHT = 0.08;
export const DEFAULT_SIGNATURE_X = 0.1;

export type SignatureRole = SignaturePlacementItem['role'];

export type SignatureSlotGroup = {
    slotKey: string;
    role: SignatureRole;
    label: string;
    placements: SignaturePlacementItem[];
};

export function slotKeyForRole(
    role: SignatureRole,
    occurrence: number,
): string {
    if (role === 'subject') {
        return SUBJECT_SLOT;
    }

    return role === 'manager'
        ? `manager_${occurrence}`
        : `company_signatory_${occurrence}`;
}

export function roleForSlot(slotKey: string): SignatureRole {
    if (slotKey === SUBJECT_SLOT) {
        return 'subject';
    }

    if (slotKey.startsWith('manager_')) {
        return 'manager';
    }

    return 'company_signatory';
}

export function occurrenceForSlot(slotKey: string): number {
    if (slotKey === SUBJECT_SLOT) {
        return 1;
    }

    const match = /_(\d+)$/.exec(slotKey);

    return match ? Number(match[1]) : 1;
}

export function signerLabel(slotKey: string): string {
    const role = roleForSlot(slotKey);
    const occ = occurrenceForSlot(slotKey);

    if (role === 'subject') {
        return 'Employee Signature';
    }

    if (role === 'manager') {
        return occ === 1 ? 'Department Manager' : `Management level ${occ}`;
    }

    return occ === 1 ? 'Company Signatory' : `Company Signatory ${occ}`;
}

export function signerKindLabel(role: SignatureRole): string {
    if (role === 'subject') {
        return 'Employee';
    }

    if (role === 'manager') {
        return 'Department Manager';
    }

    return 'Company Signatory';
}

export function defaultPlacementIdForSlot(slotKey: string): string {
    if (slotKey === SUBJECT_SLOT) {
        return 'subject_signature';
    }

    const managerMatch = /^manager_(\d+)$/.exec(slotKey);

    if (managerMatch) {
        const occ = Number(managerMatch[1]);

        return occ === 1 ? 'manager_signature' : `manager_signature_${occ}`;
    }

    const companyMatch = /^company_signatory_(\d+)$/.exec(slotKey);

    if (companyMatch) {
        const occ = Number(companyMatch[1]);

        return occ === 1
            ? 'company_signatory_signature'
            : `company_signatory_signature_${occ}`;
    }

    return `${slotKey}_signature`;
}

export function uniquePlacementId(
    existingIds: Iterable<string>,
    slotKey: string,
): string {
    const used = new Set(existingIds);
    const base = defaultPlacementIdForSlot(slotKey);

    if (!used.has(base)) {
        return base;
    }

    let n = 2;

    while (used.has(`${base}_${n}`)) {
        n += 1;
    }

    return `${base}_${n}`;
}

export function defaultYForRole(
    role: SignatureRole,
    occurrence: number,
): number {
    if (role === 'subject') {
        return 0.75;
    }

    if (role === 'manager') {
        return Math.max(0.2, 0.62 - (occurrence - 1) * 0.1);
    }

    return Math.max(0.15, 0.5 - (occurrence - 1) * 0.1);
}

export function defaultSignaturePlacement(
    slotKey: string,
    page: number,
    existingIds: Iterable<string> = [],
): SignaturePlacementItem {
    const role = roleForSlot(slotKey);
    const occ = occurrenceForSlot(slotKey);

    return {
        id: uniquePlacementId(existingIds, slotKey),
        type: 'signature',
        role,
        slot_key: slotKey,
        page,
        x: DEFAULT_SIGNATURE_X,
        y: defaultYForRole(role, occ),
        width: DEFAULT_SIGNATURE_WIDTH,
        height: DEFAULT_SIGNATURE_HEIGHT,
        required: true,
    };
}

export function loadSignaturePlacements(
    initialConfig: SignaturePlacementConfig | null,
): SignaturePlacementItem[] {
    const source = initialConfig?.placements ?? [];

    if (source.length === 0) {
        if (initialConfig == null) {
            return [defaultSignaturePlacement(SUBJECT_SLOT, 1)];
        }

        return [];
    }

    return source.map((item) => {
        const slotKey = item.slot_key ?? slotKeyForRole(item.role, 1);

        return {
            id: item.id || uniquePlacementId([], slotKey),
            type: 'signature' as const,
            role: roleForSlot(slotKey),
            slot_key: slotKey,
            page: item.page || 1,
            x: item.x,
            y: item.y,
            width: item.width,
            height: item.height,
            required: item.required ?? true,
        };
    });
}

export function serializeSignaturePlacements(
    placements: SignaturePlacementItem[],
): SignaturePlacementConfig {
    return {
        schema_version: 3,
        placements: placements.map((item) => ({
            ...item,
            type: 'signature' as const,
            role: roleForSlot(item.slot_key ?? slotKeyForRole(item.role, 1)),
            slot_key: item.slot_key ?? slotKeyForRole(item.role, 1),
            required: item.required ?? true,
        })),
    };
}

export function distinctSlotKeys(
    placements: SignaturePlacementItem[],
): string[] {
    const seen = new Set<string>();
    const keys: string[] = [];

    for (const item of placements) {
        const slotKey = item.slot_key ?? slotKeyForRole(item.role, 1);

        if (!seen.has(slotKey)) {
            seen.add(slotKey);
            keys.push(slotKey);
        }
    }

    return keys.sort(compareSlotKeys);
}

export function compareSlotKeys(a: string, b: string): number {
    const roleOrder = (slot: string): number => {
        const role = roleForSlot(slot);

        return role === 'subject' ? 0 : role === 'manager' ? 1 : 2;
    };
    const diff = roleOrder(a) - roleOrder(b);

    return diff !== 0 ? diff : occurrenceForSlot(a) - occurrenceForSlot(b);
}

export function groupSignatureSlots(
    placements: SignaturePlacementItem[],
): SignatureSlotGroup[] {
    const grouped = new Map<string, SignaturePlacementItem[]>();

    for (const item of placements) {
        const slotKey = item.slot_key ?? slotKeyForRole(item.role, 1);
        const list = grouped.get(slotKey) ?? [];
        list.push(item);
        grouped.set(slotKey, list);
    }

    return distinctSlotKeys(placements).map((slotKey) => ({
        slotKey,
        role: roleForSlot(slotKey),
        label: signerLabel(slotKey),
        placements: grouped.get(slotKey) ?? [],
    }));
}

export function nextSignerOccurrence(
    placements: SignaturePlacementItem[],
    role: Exclude<SignatureRole, 'subject'>,
): number | null {
    const existing = distinctSlotKeys(placements)
        .filter((slot) => roleForSlot(slot) === role)
        .map(occurrenceForSlot)
        .sort((a, b) => a - b);
    const next = existing.length + 1;

    return next > MAX_ROLE_OCCURRENCE ? null : next;
}

export function canvasSignatureLabel(
    item: SignaturePlacementItem,
    placements: SignaturePlacementItem[],
): string {
    const slotKey = item.slot_key ?? slotKeyForRole(item.role, 1);
    const siblings = placements.filter(
        (candidate) =>
            (candidate.slot_key ?? slotKeyForRole(candidate.role, 1)) ===
            slotKey,
    );
    const label = signerLabel(slotKey);

    if (siblings.length < 2) {
        return label;
    }

    const index =
        siblings.findIndex((candidate) => candidate.id === item.id) + 1;

    return `${label} · ${index}`;
}

export function placementIndexInSlot(
    item: SignaturePlacementItem,
    placements: SignaturePlacementItem[],
): { index: number; total: number } {
    const slotKey = item.slot_key ?? slotKeyForRole(item.role, 1);
    const siblings = placements.filter(
        (candidate) =>
            (candidate.slot_key ?? slotKeyForRole(candidate.role, 1)) ===
            slotKey,
    );

    return {
        index: siblings.findIndex((candidate) => candidate.id === item.id) + 1,
        total: siblings.length,
    };
}

export function offsetNewPlacement(
    source: SignaturePlacementItem,
): SignaturePlacementItem {
    const x = Math.min(source.x + 0.03, 1 - source.width);
    const y = Math.min(source.y + 0.03, 1 - source.height);

    return {
        ...source,
        x: Math.max(0, x),
        y: Math.max(0, y),
    };
}

export function renumberLogicalSigners(
    placements: SignaturePlacementItem[],
    role: Exclude<SignatureRole, 'subject'>,
): SignaturePlacementItem[] {
    const roleSlots = distinctSlotKeys(placements).filter(
        (slot) => roleForSlot(slot) === role,
    );
    const slotMap = new Map<string, string>();

    roleSlots.forEach((oldSlot, index) => {
        slotMap.set(oldSlot, slotKeyForRole(role, index + 1));
    });

    return placements.map((item) => {
        const currentSlot = item.slot_key ?? slotKeyForRole(item.role, 1);

        if (roleForSlot(currentSlot) !== role) {
            return item;
        }

        const nextSlot = slotMap.get(currentSlot) ?? currentSlot;

        return {
            ...item,
            role,
            slot_key: nextSlot,
        };
    });
}

export function removeSignaturePlacement(
    placements: SignaturePlacementItem[],
    placementId: string,
): SignaturePlacementItem[] {
    const removed = placements.find((item) => item.id === placementId);

    if (!removed) {
        return placements;
    }

    const slotKey = removed.slot_key ?? slotKeyForRole(removed.role, 1);
    const remaining = placements.filter((item) => item.id !== placementId);
    const stillHasSlot = remaining.some(
        (item) => (item.slot_key ?? slotKeyForRole(item.role, 1)) === slotKey,
    );

    if (stillHasSlot || roleForSlot(slotKey) === 'subject') {
        return remaining;
    }

    return renumberLogicalSigners(
        remaining,
        roleForSlot(slotKey) as Exclude<SignatureRole, 'subject'>,
    );
}

export function slotPagesFromPlacements(
    placements: SignaturePlacementItem[],
): Record<string, number> {
    const pages: Record<string, number> = {};

    for (const item of placements) {
        const slotKey = item.slot_key ?? slotKeyForRole(item.role, 1);

        if (pages[slotKey] == null) {
            pages[slotKey] = item.page;
        }
    }

    return pages;
}

export function updateSignaturePlacement(
    placements: SignaturePlacementItem[],
    id: string,
    patch: Partial<SignaturePlacementItem>,
): SignaturePlacementItem[] {
    return placements.map((item) =>
        item.id === id ? { ...item, ...patch } : item,
    );
}
