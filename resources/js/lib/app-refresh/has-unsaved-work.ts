type UnsavedWorkEnvironment = {
    documentElement?: { dataset?: DOMStringMap | Record<string, string> };
    querySelector?: (selector: string) => unknown;
    dispatchBeforeUnload?: () => boolean;
};

/**
 * Detect unsaved form work by synthesizing a cancelable beforeunload event.
 * Pages that already register beforeunload guards (employee, crew, etc.) will
 * call preventDefault when dirty.
 */
export function detectUnsavedWork(
    environment: UnsavedWorkEnvironment = {},
): boolean {
    const documentElement = environment.documentElement;

    if (documentElement?.dataset?.unsavedChanges === 'true') {
        return true;
    }

    if (environment.querySelector?.('[data-unsaved-changes="true"]')) {
        return true;
    }

    if (environment.dispatchBeforeUnload) {
        return environment.dispatchBeforeUnload();
    }

    return false;
}

export function hasUnsavedWork(): boolean {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return false;
    }

    return detectUnsavedWork({
        documentElement: document.documentElement,
        querySelector: (selector) => document.querySelector(selector),
        dispatchBeforeUnload: () => {
            const event = new Event('beforeunload', { cancelable: true });
            window.dispatchEvent(event);

            return event.defaultPrevented;
        },
    });
}
