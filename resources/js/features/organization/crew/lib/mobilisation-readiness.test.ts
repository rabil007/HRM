import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { CrewMobilisationReadiness } from '../types.ts';
import {
    hasConfiguredMobilisationChecks,
    isMobilisationReadinessProblem,
    mobilisationReadinessPresentationLabel,
    mobilisationReadinessTone,
} from './mobilisation-readiness.ts';

function readiness(
    overrides: Partial<CrewMobilisationReadiness> = {},
): CrewMobilisationReadiness {
    return {
        applies: true,
        status: 'ready',
        status_label: 'Ready',
        checks_clear: 0,
        checks_total: 0,
        advisory_note: 'Operational warning only.',
        problems: [],
        documents_href: null,
        ...overrides,
    };
}

describe('mobilisation readiness presentation', () => {
    it('uses a neutral no-checks label when nothing is configured', () => {
        const none = readiness();

        assert.equal(hasConfiguredMobilisationChecks(none), false);
        assert.equal(
            mobilisationReadinessPresentationLabel(none),
            'No Checks Configured',
        );
        assert.equal(mobilisationReadinessTone(none), 'not_assessed');
        assert.equal(isMobilisationReadinessProblem(none), false);
    });

    it('keeps not-ready as a readiness problem', () => {
        const notReady = readiness({
            status: 'not_ready',
            status_label: 'Not Ready',
            checks_clear: 4,
            checks_total: 6,
        });

        assert.equal(isMobilisationReadinessProblem(notReady), true);
        assert.equal(
            mobilisationReadinessPresentationLabel(notReady),
            'Not Ready',
        );
        assert.equal(mobilisationReadinessTone(notReady), 'not_ready');
    });
});
