import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    canvasSignatureLabel,
    distinctSlotKeys,
    groupSignatureSlots,
    loadSignaturePlacements,
    nextSignerOccurrence,
    removeSignaturePlacement,
    serializeSignaturePlacements,
    uniquePlacementId,
} from '../templates/lib/signature-placements.ts';
import type { SignaturePlacementItem } from '../templates/types.ts';

function box(
    id: string,
    slot: string,
    role: SignaturePlacementItem['role'],
    x = 0.1,
): SignaturePlacementItem {
    return {
        id,
        type: 'signature',
        role,
        slot_key: slot,
        page: 1,
        x,
        y: 0.7,
        width: 0.25,
        height: 0.08,
        required: true,
    };
}

describe('signature placements', () => {
    it('loads two subject boxes without collapsing them', () => {
        const loaded = loadSignaturePlacements({
            schema_version: 3,
            placements: [
                box('employee_signature_en', 'subject', 'subject', 0.1),
                box('employee_signature_ar', 'subject', 'subject', 0.6),
            ],
        });

        assert.equal(loaded.length, 2);
        assert.deepEqual(
            loaded.map((item) => item.id),
            ['employee_signature_en', 'employee_signature_ar'],
        );
        assert.deepEqual(distinctSlotKeys(loaded), ['subject']);
    });

    it('serializes schema v3 and keeps distinct physical ids', () => {
        const saved = serializeSignaturePlacements([
            box('subject_signature', 'subject', 'subject'),
            box('subject_signature_2', 'subject', 'subject', 0.5),
        ]);

        assert.equal(saved.schema_version, 3);
        assert.equal(saved.placements.length, 2);
        assert.notEqual(saved.placements[0]?.id, saved.placements[1]?.id);
    });

    it('counts logical manager signers, not physical boxes', () => {
        const placements = [
            box('manager_signature', 'manager_1', 'manager'),
            box('manager_signature_2', 'manager_1', 'manager', 0.4),
            box('manager_signature_2b', 'manager_2', 'manager', 0.7),
        ];

        const groups = groupSignatureSlots(placements);

        assert.equal(groups.length, 2);
        assert.equal(nextSignerOccurrence(placements, 'manager'), 3);
        assert.equal(groups[0]?.placements.length, 2);
    });

    it('deleting one physical box keeps the signer', () => {
        const remaining = removeSignaturePlacement(
            [
                box('subject_signature', 'subject', 'subject'),
                box('subject_signature_2', 'subject', 'subject', 0.5),
            ],
            'subject_signature_2',
        );

        assert.equal(remaining.length, 1);
        assert.equal(remaining[0]?.id, 'subject_signature');
        assert.deepEqual(distinctSlotKeys(remaining), ['subject']);
    });

    it('numbers canvas labels only when a slot has multiple boxes', () => {
        const two = [
            box('subject_signature', 'subject', 'subject'),
            box('subject_signature_2', 'subject', 'subject', 0.5),
        ];

        assert.equal(
            canvasSignatureLabel(two[0]!, two),
            'Employee Signature · 1',
        );
        assert.equal(
            canvasSignatureLabel(two[0]!, [two[0]!]),
            'Employee Signature',
        );
    });

    it('generates stable unique ids from the slot seed', () => {
        assert.equal(uniquePlacementId([], 'subject'), 'subject_signature');
        assert.equal(
            uniquePlacementId(['subject_signature'], 'subject'),
            'subject_signature_2',
        );
    });

    it('adding a second employee box keeps the subject slot', () => {
        const first = box('subject_signature', 'subject', 'subject');
        const second = {
            ...first,
            id: uniquePlacementId([first.id], 'subject'),
            x: 0.5,
        };

        const saved = serializeSignaturePlacements([first, second]);

        assert.equal(second.id, 'subject_signature_2');
        assert.equal(second.slot_key, 'subject');
        assert.equal(saved.schema_version, 3);
        assert.equal(
            groupSignatureSlots([first, second])[0]?.placements.length,
            2,
        );
    });

    it('deleting the last employee box leaves the slot empty without inventing a manager', () => {
        const remaining = removeSignaturePlacement(
            [box('subject_signature', 'subject', 'subject')],
            'subject_signature',
        );

        assert.equal(remaining.length, 0);
        assert.deepEqual(distinctSlotKeys(remaining), []);
    });
});
