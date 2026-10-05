import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    createRequirementFormSnapshot,
    dedupeNotificationRecipientIds,
    firstInvalidRequirementField,
    isRequirementFormDirty,
    resolveDuplicateDialogSubmitIntent,
} from './requirement-form.ts';

describe('requirement form helpers', () => {
    it('detects dirty form state against a baseline snapshot', () => {
        const baseline = createRequirementFormSnapshot({
            client_id: '1',
            project_id: '',
            location: '',
            assigned_to: '',
            notification_recipient_ids: [],
            request_received_date: '2026-10-01',
            required_by_date: '2026-10-15',
            priority: 'normal',
            notes: '',
            positions: [{ position_id: '4', required_headcount: 2 }],
            attachment: null,
        });

        assert.equal(isRequirementFormDirty(baseline, baseline), false);
        assert.equal(
            isRequirementFormDirty(
                baseline,
                createRequirementFormSnapshot({
                    client_id: baseline.client_id,
                    project_id: baseline.project_id,
                    location: baseline.location,
                    assigned_to: baseline.assigned_to,
                    notification_recipient_ids:
                        baseline.notification_recipient_ids,
                    request_received_date: baseline.request_received_date,
                    required_by_date: baseline.required_by_date,
                    priority: baseline.priority,
                    notes: 'Updated',
                    positions: [{ position_id: '4', required_headcount: 2 }],
                    attachment: null,
                }),
            ),
            true,
        );
        assert.equal(
            isRequirementFormDirty(
                baseline,
                createRequirementFormSnapshot({
                    client_id: baseline.client_id,
                    project_id: baseline.project_id,
                    location: baseline.location,
                    assigned_to: baseline.assigned_to,
                    notification_recipient_ids:
                        baseline.notification_recipient_ids,
                    request_received_date: baseline.request_received_date,
                    required_by_date: baseline.required_by_date,
                    priority: baseline.priority,
                    notes: baseline.notes,
                    positions: [
                        {
                            position_id: '4',
                            required_headcount: 2,
                            salary_min: '5000',
                            salary_max: '7000',
                        },
                    ],
                    attachment: null,
                }),
            ),
            true,
        );
    });

    it('dedupes notification recipient ids', () => {
        assert.deepEqual(
            dedupeNotificationRecipientIds([3, 3, 0, 5, -1]),
            [3, 5],
        );
    });

    it('returns the first invalid field in display order', () => {
        assert.equal(
            firstInvalidRequirementField({
                notes: 'Too long',
                client_id: 'Required',
                'positions.0.position_id': 'Required',
            }),
            'client_id',
        );
        assert.equal(
            firstInvalidRequirementField({
                'positions.0.required_headcount': 'Min 1',
            }),
            'positions',
        );
        assert.equal(
            firstInvalidRequirementField({
                'notification_recipient_ids.0': 'Invalid',
            }),
            'notification_recipient_ids',
        );
        assert.equal(firstInvalidRequirementField({}), null);
    });

    it('preserves save-and-submit intent through duplicate create-separate decision', () => {
        assert.deepEqual(
            resolveDuplicateDialogSubmitIntent(true, 'create_separate'),
            { submitForApproval: true, clearPendingIntent: true },
        );
        assert.deepEqual(
            resolveDuplicateDialogSubmitIntent(false, 'create_separate'),
            { submitForApproval: false, clearPendingIntent: true },
        );
        assert.deepEqual(
            resolveDuplicateDialogSubmitIntent(true, 'return_and_review'),
            { submitForApproval: false, clearPendingIntent: true },
        );
        assert.deepEqual(resolveDuplicateDialogSubmitIntent(true, 'dismiss'), {
            submitForApproval: false,
            clearPendingIntent: true,
        });
    });
});
