import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import type { ActionImpactPreviewProps } from './action-impact-preview.ts';

function hasRequiredFields(props: ActionImpactPreviewProps): boolean {
    return Boolean(
        props.title ||
        props.subject ||
        props.currentState ||
        props.destinationState ||
        (props.impacts?.length ?? 0) > 0 ||
        props.warning,
    );
}

describe('ActionImpactPreview props shape', () => {
    it('supports full high-impact transfer preview fields', () => {
        const props: ActionImpactPreviewProps = {
            title: 'What will happen',
            subject: 'Abdul Hamid',
            currentState: 'P4 · Sea Eagle',
            destinationState: 'Sea Falcon',
            impacts: [
                'The current P4 On Vessel phase ends at the selected movement time.',
            ],
            severity: 'high',
            movementTime: '17 Sep 2026 · 11:30',
        };

        assert.equal(props.severity, 'high');
        assert.ok(hasRequiredFields(props));
    });

    it('supports destructive cancel preview fields', () => {
        const props: ActionImpactPreviewProps = {
            title: 'What will happen',
            subject: 'CA-2026-000041\nAbdul Hamid',
            impacts: ['The assignment is marked Cancelled when permitted.'],
            severity: 'destructive',
        };

        assert.equal(props.severity, 'destructive');
        assert.ok(hasRequiredFields(props));
    });

    it('supports compact light-impact preview fields', () => {
        const props: ActionImpactPreviewProps = {
            subject: 'Abdul Hamid',
            currentState: 'Sea Falcon',
            impacts: ['This will start: P4 · On Vessel'],
            compact: true,
            severity: 'normal',
            movementTime: '17 Sep 2026 · 12:00',
        };

        assert.equal(props.compact, true);
        assert.ok(hasRequiredFields(props));
    });
});
