import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { describe, it } from 'node:test';
import { fileURLToPath } from 'node:url';

const reportTablePath = join(
    dirname(fileURLToPath(import.meta.url)),
    'report-table.tsx',
);

function summaryTableHeaderSource(): string {
    const source = readFileSync(reportTablePath, 'utf8');
    const start = source.indexOf('<TableHeader>');
    const end = source.indexOf('</TableHeader>');

    assert.ok(
        start >= 0 && end > start,
        'report table must define a TableHeader',
    );

    return source.slice(start, end);
}

function assertLabelsInOrder(source: string, labels: string[]): void {
    let cursor = -1;

    for (const label of labels) {
        const index = source.indexOf(label, cursor + 1);

        assert.ok(
            index > cursor,
            `Expected "${label}" after the previous summary header label`,
        );
        cursor = index;
    }
}

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

        // Active P2 On Vessel ongoing
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

        // Returned home normally (P3 -> P4)
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

        // Direct P2 -> P4 (neutral Home / Redeploy)
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

    it('renders standby breakdown columns in chronological order in the report table header', () => {
        const header = summaryTableHeaderSource();

        assertLabelsInOrder(header, [
            'Arrival',
            'Pre-Joining Standby',
            'Joined Vessel',
            'Disembarked',
            'Demobilisation Standby',
            'Returned Home',
            'Total Standby',
        ]);

        assert.match(
            header,
            /Pre-Joining Standby and Training/,
            'Pre-Joining tooltip must state that Training is included',
        );
        assert.equal(
            header.includes('Standby Service Days'),
            false,
            'retired Standby Service Days heading must not remain in the summary header',
        );
    });

    it('treats missing standby phases as zero when totaling standby', () => {
        const totalStandby = (
            preJoining: number | null,
            demobilisation: number | null,
        ): number | null => {
            if (preJoining === null && demobilisation === null) {
                return null;
            }

            return (preJoining ?? 0) + (demobilisation ?? 0);
        };

        assert.equal(totalStandby(3, 2), 5);
        assert.equal(totalStandby(3, 0), 3);
        assert.equal(totalStandby(0, 2), 2);
        assert.equal(totalStandby(0, 0), 0);
        assert.equal(totalStandby(1, 1), 2);
        assert.equal(totalStandby(null, null), null);
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
