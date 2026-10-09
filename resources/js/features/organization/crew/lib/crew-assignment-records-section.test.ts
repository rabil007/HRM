import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import React from 'react';
import { renderToString } from 'react-dom/server';
import { createServer } from 'vite';
import type {
    CorrectionsSummary,
    CrewAccommodationSummaryItem,
    CrewAssignmentDetail,
    CrewAssignmentPagePermissions,
} from '../types.ts';

function basePermissions(
    overrides: Partial<CrewAssignmentPagePermissions> = {},
): CrewAssignmentPagePermissions {
    return {
        view: true,
        create: false,
        start: true,
        update: true,
        cancel: true,
        void: true,
        perform_movement: true,
        view_planning: true,
        view_corrections: true,
        request_correction: true,
        approve_corrections: true,
        override_corrections: true,
        view_training: true,
        view_audit: true,
        view_documents: true,
        view_employee: true,
        ...overrides,
    };
}

function assignment(
    overrides: Partial<CrewAssignmentDetail> = {},
): CrewAssignmentDetail {
    return {
        id: 1,
        assignment_no: 'CA-2026-000001',
        status: 'active',
        status_label: 'Active',
        remarks: 'Handle with care',
        previous_assignment: {
            id: 9,
            assignment_no: 'CA-2026-000009',
            status_label: 'Completed',
            vessel_name: 'Ocean Star',
        },
        next_assignments: [],
        relieves: null,
        planning_assignment_id: 44,
        accommodation: [
            {
                id: 3,
                stay_type_label: 'Pre-join',
                accommodation_status: 'hotel',
                hotel_name: 'Marina Hotel',
                room_type_name: 'Twin',
                check_in_date: '2026-09-01',
                check_out_date: null,
                is_open: true,
                stay_days: 8,
            } satisfies CrewAccommodationSummaryItem,
        ],
        ...overrides,
    } as CrewAssignmentDetail;
}

function corrections(
    overrides: Partial<CorrectionsSummary> = {},
): CorrectionsSummary {
    return {
        pending: [],
        history: [],
        pending_count: 2,
        approved_count: 0,
        correctable_phases: [],
        ...overrides,
    };
}

async function withViteModule<T>(
    importer: (
        load: (path: string) => Promise<Record<string, unknown>>,
    ) => Promise<T>,
): Promise<T> {
    const vite = await createServer({
        configFile: false,
        plugins: [(await import('@vitejs/plugin-react')).default()],
        resolve: {
            alias: {
                '@': new URL('../../../../', import.meta.url).pathname,
            },
        },
    });

    try {
        return await importer((path) => vite.ssrLoadModule(path));
    } finally {
        await vite.close();
    }
}

describe('Crew Assignment Records collapsible sections', () => {
    it('renders independently collapsible records with operational defaults', async () => {
        const html = await withViteModule(async (load) => {
            const { CrewAssignmentRecordsSection } = await load(
                './resources/js/features/organization/crew/components/crew-assignment-records-section.tsx',
            );
            const { CrewAssignmentRelationships } = await load(
                './resources/js/features/organization/crew/components/crew-assignment-relationships.tsx',
            );
            const { CrewAssignmentAccommodationCard } = await load(
                './resources/js/features/organization/crew/components/crew-assignment-accommodation-card.tsx',
            );
            const { CorrectionHistoryCard } = await load(
                './resources/js/features/organization/crew/corrections/correction-history-card.tsx',
            );
            const { CrewAssignmentRemarksRecord, CrewAssignmentAuditRecord } =
                await load(
                    './resources/js/features/organization/crew/components/crew-assignment-records-section.tsx',
                );

            const detail = assignment();
            const can = basePermissions();

            return renderToString(
                React.createElement(
                    CrewAssignmentRecordsSection as React.ComponentType<{
                        children: React.ReactNode;
                    }>,
                    null,
                    React.createElement(
                        CrewAssignmentRelationships as React.ComponentType<{
                            assignment: CrewAssignmentDetail;
                            canViewPlanning?: boolean;
                        }>,
                        {
                            assignment: detail,
                            canViewPlanning: can.view_planning,
                        },
                    ),
                    React.createElement(
                        CrewAssignmentAccommodationCard as React.ComponentType<{
                            items: CrewAccommodationSummaryItem[];
                        }>,
                        { items: detail.accommodation ?? [] },
                    ),
                    React.createElement(
                        CorrectionHistoryCard as React.ComponentType<{
                            corrections: CorrectionsSummary;
                        }>,
                        { corrections: corrections() },
                    ),
                    React.createElement(
                        CrewAssignmentRemarksRecord as React.ComponentType<{
                            remarks: string;
                        }>,
                        { remarks: detail.remarks ?? '' },
                    ),
                    React.createElement(
                        CrewAssignmentAuditRecord as React.ComponentType<{
                            items: [];
                            canViewAudit: boolean;
                        }>,
                        { items: [], canViewAudit: can.view_audit },
                    ),
                ),
            );
        });

        assert.ok(
            html.includes('Assignment Records &amp; History') ||
                html.includes('Assignment Records & History'),
        );
        assert.ok(html.includes('data-slot="assignment-relationships"'));
        assert.ok(html.includes('data-slot="assignment-accommodation"'));
        assert.ok(html.includes('data-slot="assignment-correction-history"'));
        assert.ok(html.includes('data-slot="assignment-remarks"'));
        assert.ok(html.includes('data-slot="assignment-audit-history"'));
        // Collapsed headers keep titles/status visible.
        assert.ok(html.includes('Assignment Relationships'));
        assert.ok(html.includes('Remarks'));
        assert.ok(html.includes('Audit History'));
        assert.ok(html.includes('Open stay'));
        assert.ok(html.includes('2 pending'));
        // Operationally important sections default open, so body content is present.
        assert.ok(html.includes('Marina Hotel'));
        assert.ok(
            html.includes(
                'No correction requests recorded for this assignment yet.',
            ),
        );
    });

    it('hides correction and audit sections when permissions deny them', async () => {
        const html = await withViteModule(async (load) => {
            const { CorrectionHistoryCard } = await load(
                './resources/js/features/organization/crew/corrections/correction-history-card.tsx',
            );
            const { CrewAssignmentAuditRecord } = await load(
                './resources/js/features/organization/crew/components/crew-assignment-records-section.tsx',
            );

            const can = basePermissions({
                view_corrections: false,
                view_audit: false,
            });

            const correctionHtml = can.view_corrections
                ? renderToString(
                      React.createElement(
                          CorrectionHistoryCard as React.ComponentType<{
                              corrections: CorrectionsSummary;
                          }>,
                          { corrections: corrections() },
                      ),
                  )
                : '';

            const auditHtml = renderToString(
                React.createElement(
                    CrewAssignmentAuditRecord as React.ComponentType<{
                        items: [];
                        canViewAudit: boolean;
                    }>,
                    { items: [], canViewAudit: can.view_audit },
                ),
            );

            return { correctionHtml, auditHtml };
        });

        assert.equal(html.correctionHtml, '');
        assert.equal(html.auditHtml, '');
    });
});
