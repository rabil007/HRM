import type { PlanningDepartmentNode } from '../types';

export function collectSubtreeIds(node: PlanningDepartmentNode): number[] {
    const ids = [node.id];

    for (const child of node.children) {
        ids.push(...collectSubtreeIds(child));
    }

    return ids;
}

export function flattenDepartmentTreeIds(nodes: PlanningDepartmentNode[]): number[] {
    const ids: number[] = [];

    for (const node of nodes) {
        ids.push(...collectSubtreeIds(node));
    }

    return ids;
}

export type DepartmentCheckState = 'checked' | 'unchecked' | 'indeterminate';

export function getDepartmentCheckState(
    node: PlanningDepartmentNode,
    selectedIds: Set<number>,
): DepartmentCheckState {
    const subtreeIds = collectSubtreeIds(node);
    const selectedCount = subtreeIds.filter((id) => selectedIds.has(id)).length;

    if (selectedCount === 0) {
        return 'unchecked';
    }

    if (selectedCount === subtreeIds.length) {
        return 'checked';
    }

    return 'indeterminate';
}

export function applyDepartmentToggle(
    selectedIds: number[],
    node: PlanningDepartmentNode,
    checked: boolean,
): number[] {
    const subtreeIds = new Set(collectSubtreeIds(node));
    const next = new Set(selectedIds);

    if (checked) {
        for (const id of subtreeIds) {
            next.add(id);
        }
    } else {
        for (const id of subtreeIds) {
            next.delete(id);
        }
    }

    return [...next];
}
