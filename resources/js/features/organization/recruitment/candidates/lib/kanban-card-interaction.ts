/**
 * Helpers for whole-card Kanban drag vs click-to-open-detail.
 */

type ClosestCapable = {
    closest: (selectors: string) => unknown;
};

export function isInteractiveKanbanTarget(target: EventTarget | null): boolean {
    if (
        target === null ||
        typeof target !== 'object' ||
        !('closest' in target) ||
        typeof (target as ClosestCapable).closest !== 'function'
    ) {
        return false;
    }

    return Boolean(
        (target as ClosestCapable).closest(
            'a, button, input, textarea, select, [role="button"], [role="menuitem"], [data-kanban-interactive="true"]',
        ),
    );
}

/**
 * After a drag gesture, @dnd-kit may still emit a click. Skip navigation when
 * the pointer moved beyond the activation threshold or a drag was active.
 */
export function shouldNavigateKanbanCardClick(options: {
    isDragging: boolean;
    didDrag: boolean;
    target: EventTarget | null;
}): boolean {
    if (options.isDragging || options.didDrag) {
        return false;
    }

    if (isInteractiveKanbanTarget(options.target)) {
        return false;
    }

    return true;
}
