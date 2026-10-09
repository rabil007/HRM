type UnsavedWorkEnvironment = {
    documentElement?: { dataset?: DOMStringMap | Record<string, string> };
    querySelector?: (selector: string) => unknown;
    dispatchBeforeUnload?: () => boolean;
    checkers?: Iterable<() => boolean>;
};

const dirtyCheckers = new Set<() => boolean>();

let intentionalUnload = false;
let captureGuardInstalled = false;

/**
 * Register a dirty-state checker used before hard reloads.
 * Returns an unregister function.
 */
export function registerUnsavedWorkChecker(checker: () => boolean): () => void {
    dirtyCheckers.add(checker);

    return () => {
        dirtyCheckers.delete(checker);
    };
}

/**
 * Suppress native beforeunload prompts for the next intentional reload after
 * the user has already confirmed discarding unsaved work.
 */
export function markIntentionalUnload(): void {
    intentionalUnload = true;
    ensureIntentionalUnloadCaptureGuard();
}

export function isIntentionalUnload(): boolean {
    return intentionalUnload;
}

function ensureIntentionalUnloadCaptureGuard(): void {
    if (captureGuardInstalled || typeof window === 'undefined') {
        return;
    }

    captureGuardInstalled = true;
    window.addEventListener(
        'beforeunload',
        (event) => {
            if (!intentionalUnload) {
                return;
            }

            event.stopImmediatePropagation();
        },
        true,
    );
}

/**
 * Detect unsaved form work via registry checkers, DOM markers, and
 * synthesizing a cancelable beforeunload event for existing page guards.
 */
export function detectUnsavedWork(
    environment: UnsavedWorkEnvironment = {},
): boolean {
    for (const checker of environment.checkers ?? dirtyCheckers) {
        try {
            if (checker()) {
                return true;
            }
        } catch {
            // Ignore broken checkers.
        }
    }

    const documentElement = environment.documentElement;

    if (documentElement?.dataset?.unsavedChanges === 'true') {
        return true;
    }

    if (environment.querySelector?.('[data-unsaved-changes="true"]')) {
        return true;
    }

    if (environment.querySelector?.('[data-upload-in-progress="true"]')) {
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
        checkers: dirtyCheckers,
        dispatchBeforeUnload: () => {
            const event = new Event('beforeunload', { cancelable: true });
            window.dispatchEvent(event);

            return event.defaultPrevented;
        },
    });
}
