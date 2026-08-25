import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { VesselRow } from '../types.ts';
import { vesselMobileCardModel } from './vessel-mobile-card.ts';

function vessel(overrides: Partial<VesselRow> = {}): VesselRow {
    return {
        id: 7,
        name: 'Horizon Star',
        vessel_type_id: 1,
        vessel_type: { id: 1, name: 'AHTS' },
        vessel_type_name: 'AHTS',
        grt: '2500',
        bhp: 8000,
        official_no: 'OFF-12',
        call_sign: 'A4HRZ',
        imo_no: 'IMO1234567',
        certificate_original_filename: 'cert.pdf',
        certificate_url: '/private/certificates/cert.pdf',
        is_active: true,
        manning: [
            { id: 1, rank_id: 2, rank_name: 'Master', required_count: 1 },
        ],
        total_required: 8,
        ranks_configured: 3,
        ...overrides,
    };
}

describe('vesselMobileCardModel', () => {
    it('derives identity, type, manning, and status for the compact card', () => {
        const model = vesselMobileCardModel(vessel(), {
            update: false,
            delete: false,
        });

        assert.equal(model.title, 'Horizon Star');
        assert.equal(model.subtitle, 'A4HRZ');
        assert.equal(model.typeLine, 'AHTS');
        assert.equal(
            model.identificationLine,
            'IMO IMO1234567 · Official OFF-12',
        );
        assert.equal(model.manningLine, '3 ranks · 8 required');
        assert.equal(model.statusLabel, 'Active');
        assert.equal(model.attention, null);
        assert.equal(model.showEdit, false);
        assert.equal(model.showDelete, false);
    });

    it('does not put certificate files on the card model', () => {
        const model = vesselMobileCardModel(vessel(), {
            update: true,
            delete: true,
        });

        assert.equal(JSON.stringify(model).includes('cert.pdf'), false);
        assert.equal(
            JSON.stringify(model).includes('/private/certificates'),
            false,
        );
    });

    it('gates mutation actions by permission', () => {
        const viewOnly = vesselMobileCardModel(vessel(), {
            update: false,
            delete: false,
        });
        const updater = vesselMobileCardModel(vessel(), {
            update: true,
            delete: false,
        });
        const deleter = vesselMobileCardModel(vessel(), {
            update: false,
            delete: true,
        });

        assert.equal(viewOnly.showEdit, false);
        assert.equal(viewOnly.showDelete, false);
        assert.equal(updater.showEdit, true);
        assert.equal(deleter.showDelete, true);
    });

    it('flags vessels without manning', () => {
        const model = vesselMobileCardModel(
            vessel({ ranks_configured: 0, total_required: 0, manning: [] }),
            { update: false, delete: false },
        );

        assert.equal(model.manningLine, 'No manning configured');
        assert.equal(model.attention, 'Manning not configured');
    });
});
