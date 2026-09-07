import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { ReliefDeskRow } from '../types.ts';
import { reliefDeskMobileCardModel } from './relief-desk-mobile-card.ts';

function row(overrides: Partial<ReliefDeskRow> = {}): ReliefDeskRow {
    return {
        id: 10,
        assignment_no: 'CA-2026-000010',
        source_href: '/organization/crew/10',
        employee: {
            id: 1,
            name: 'Ahmed Ali',
            employee_no: 'E1',
            href: '/organization/employees/1',
        },
        vessel: {
            id: 2,
            name: 'Ocean Star',
            href: '/organization/vessels/2',
        },
        rank: { id: 3, name: 'AB' },
        current_phase_code: 'p4',
        current_phase_label: 'On Vessel',
        current_duty_day: 82,
        days_onboard: 81,
        planned_signoff_at: '2026-09-12',
        days_until_signoff: 5,
        missing_planned_signoff: false,
        relief_status: 'mobilising',
        relief_status_label: 'Mobilising',
        relief_risk: 'critical',
        relief_risk_label: 'Critical',
        relief_employee: {
            id: 4,
            name: 'Sameer Khan',
            employee_no: 'E4',
            href: null,
        },
        relief_planning_assignment_id: 9,
        relief_crew_assignment_id: 11,
        relief_phase_code: 'p1',
        relief_phase_label: 'Travel In',
        relief_planned_join_date: '2026-09-12',
        mobilisation_readiness: {
            applies: true,
            status: 'ready',
            status_label: 'Ready',
            checks_clear: 1,
            checks_total: 1,
            advisory_note: 'Operational warning only.',
            problems: [],
            documents_href: null,
        },
        recommended_action: {
            key: 'open_relief_assignment',
            label: 'Open Relief Assignment',
            href: '/organization/crew/11',
        },
        ...overrides,
    };
}

describe('reliefDeskMobileCardModel', () => {
    it('prioritizes vessel, crew, sign-off, and relief action', () => {
        const model = reliefDeskMobileCardModel(row());

        assert.equal(model.title, 'Ocean Star · AB');
        assert.match(model.subtitle, /Ahmed Ali/);
        assert.match(model.signoff, /5d/);
        assert.equal(model.relief, 'Sameer Khan');
        assert.equal(model.reliefPhase, 'Travel In');
        assert.equal(model.readiness, 'Ready');
        assert.equal(model.risk, 'Critical');
        assert.equal(model.actionLabel, 'Open Relief Assignment');
    });

    it('shows no relief without inventing a replacement name', () => {
        const model = reliefDeskMobileCardModel(
            row({
                relief_status: 'no_relief',
                relief_status_label: 'No Relief',
                relief_employee: null,
                recommended_action: {
                    key: 'plan_relief',
                    label: 'Plan Relief',
                    href: '/organization/crew-planning?open_create=1',
                },
            }),
        );

        assert.equal(model.relief, 'No Relief');
        assert.equal(model.actionLabel, 'Plan Relief');
    });
});
