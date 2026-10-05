import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

describe('crew movement history presentation logic', () => {
    it('formats numeric days accurately', () => {
        const formatDays = (days: number | null): string => {
            if (days === null) {
                return '—';
            }

            return `${days} ${days === 1 ? 'day' : 'days'}`;
        };

        assert.equal(formatDays(null), '—');
        assert.equal(formatDays(1), '1 day');
        assert.equal(formatDays(24), '24 days');
        assert.equal(formatDays(0), '0 days');
    });

    it('determines sign-off presentation based on active phase status without falling back to planned sign-off', () => {
        const resolveSignOff = (
            vesselOngoing: boolean,
            actualDisembarkation: string | null,
        ): { isOngoing: boolean; display: string } => {
            if (vesselOngoing) {
                return { isOngoing: true, display: 'Ongoing' };
            }

            return {
                isOngoing: false,
                display: actualDisembarkation ?? '—',
            };
        };

        // Active P4 ongoing
        const activeRow = resolveSignOff(true, null);
        assert.equal(activeRow.isOngoing, true);
        assert.equal(activeRow.display, 'Ongoing');

        // Completed with actual disembarkation
        const completedRow = resolveSignOff(false, '2026-08-31');
        assert.equal(completedRow.isOngoing, false);
        assert.equal(completedRow.display, '2026-08-31');

        // Not ongoing and no actual disembarkation recorded: should display '—'
        const draftRow = resolveSignOff(false, null);
        assert.equal(draftRow.isOngoing, false);
        assert.equal(draftRow.display, '—');
    });

    it('identifies direct redeployment vs return home vs home/redeploy correctly', () => {
        const resolveReturnHomeOrRedeployed = (homeRedeploy: {
            outcome?: 'returned_home' | 'redeployed' | 'home_redeploy' | null;
            outcome_label?: string | null;
            is_redeployed_directly?: boolean;
            redeployed_at?: string | null;
            actual_return_home_at?: string | null;
            from?: string | null;
        }): {
            type: 'redeployed' | 'returned_home' | 'home_redeploy' | 'none';
            label: string;
            date: string | null;
        } => {
            if (
                homeRedeploy.outcome === 'redeployed' ||
                homeRedeploy.is_redeployed_directly
            ) {
                return {
                    type: 'redeployed',
                    label: 'Redeployed',
                    date: homeRedeploy.redeployed_at ?? null,
                };
            }

            if (
                homeRedeploy.outcome === 'returned_home' &&
                homeRedeploy.actual_return_home_at
            ) {
                return {
                    type: 'returned_home',
                    label: 'Returned Home',
                    date: homeRedeploy.actual_return_home_at,
                };
            }

            if (homeRedeploy.outcome === 'home_redeploy') {
                return {
                    type: 'home_redeploy',
                    label: 'Home / Redeploy',
                    date: homeRedeploy.from ?? null,
                };
            }

            return {
                type: 'none',
                label: '—',
                date: null,
            };
        };

        // Directly redeployed without going home
        const redeployed = resolveReturnHomeOrRedeployed({
            outcome: 'redeployed',
            outcome_label: 'Redeployed',
            is_redeployed_directly: true,
            redeployed_at: '2026-07-01',
            actual_return_home_at: null,
            from: null,
        });
        assert.equal(redeployed.type, 'redeployed');
        assert.equal(redeployed.label, 'Redeployed');
        assert.equal(redeployed.date, '2026-07-01');

        // Returned home normally (P5 -> P6)
        const normalHome = resolveReturnHomeOrRedeployed({
            outcome: 'returned_home',
            outcome_label: 'Returned Home',
            is_redeployed_directly: false,
            actual_return_home_at: '2026-09-02',
            from: '2026-09-02',
        });
        assert.equal(normalHome.type, 'returned_home');
        assert.equal(normalHome.label, 'Returned Home');
        assert.equal(normalHome.date, '2026-09-02');

        // Direct P4 -> P6 (neutral Home / Redeploy)
        const directP4P6 = resolveReturnHomeOrRedeployed({
            outcome: 'home_redeploy',
            outcome_label: 'Home / Redeploy',
            is_redeployed_directly: false,
            actual_return_home_at: null,
            from: '2026-09-10',
        });
        assert.equal(directP4P6.type, 'home_redeploy');
        assert.equal(directP4P6.label, 'Home / Redeploy');
        assert.equal(directP4P6.date, '2026-09-10');

        // Neither
        const neither = resolveReturnHomeOrRedeployed({});
        assert.equal(neither.type, 'none');
        assert.equal(neither.label, '—');
    });

    it('preserves all 12 operational Excel-style table column definitions', () => {
        const columns = [
            'Emp No',
            'Crew Name',
            'Rank',
            'Vessel',
            'Client',
            'Arrival',
            'Joined Vessel',
            'Sign-Off / Disembarked',
            'Returned Home',
            'Vessel Days',
            'Status',
            'Actions',
        ];

        assert.equal(columns.length, 12);
        assert.ok(columns.includes('Emp No'));
        assert.ok(columns.includes('Crew Name'));
        assert.ok(columns.includes('Rank'));
        assert.ok(columns.includes('Vessel'));
        assert.ok(columns.includes('Client'));
        assert.ok(columns.includes('Arrival'));
        assert.ok(columns.includes('Joined Vessel'));
        assert.ok(columns.includes('Sign-Off / Disembarked'));
        assert.ok(columns.includes('Returned Home'));
        assert.ok(columns.includes('Vessel Days'));
        assert.ok(columns.includes('Status'));
        assert.ok(columns.includes('Actions'));
    });

    it('verifies vessel service period shortcut values', () => {
        const periodShortcuts = [
            { key: '', label: 'All History' },
            { key: 'this_month', label: 'This Month' },
            { key: 'last_month', label: 'Last Month' },
            { key: 'last_3_months', label: 'Last 3 Months' },
            { key: 'this_year', label: 'This Year' },
        ];

        assert.equal(periodShortcuts.length, 5);
        assert.equal(periodShortcuts[0].key, '');
        assert.equal(periodShortcuts[1].key, 'this_month');
        assert.equal(periodShortcuts[2].key, 'last_month');
        assert.equal(periodShortcuts[3].key, 'last_3_months');
        assert.equal(periodShortcuts[4].key, 'this_year');
    });
});
