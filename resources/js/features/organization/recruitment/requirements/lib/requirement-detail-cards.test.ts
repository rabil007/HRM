import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import React from 'react';
import { renderToString } from 'react-dom/server';
import { createServer } from 'vite';
import type { RequirementDetail } from '@/types/recruitment';

function makeRequirement(
    overrides: Partial<RequirementDetail> = {},
): RequirementDetail {
    return {
        id: 1,
        requirement_number: 'REQ-2026-000100',
        client_id: 1,
        client_name: 'Acme Marine',
        project_id: 1,
        project_title: 'Vessel A',
        client_reference_number: null,
        has_legacy_client_reference: false,
        location: 'Dubai',
        priority: 'normal',
        priority_label: 'Normal',
        priority_badge: 'secondary',
        status: 'open',
        status_label: 'Open',
        status_badge: 'default',
        assigned_to: 9,
        assigned_recruiter_name: 'Recruiter User',
        request_received_date: '2026-01-01',
        request_received_date_formatted: '01-01-2026',
        required_by_date: '2026-01-20',
        required_by_date_formatted: '20-01-2026',
        deadline_health: 'on_track',
        days_remaining_or_overdue: 10,
        days_label: '10 days left',
        total_headcount: 3,
        positions_summary: [],
        positions_count: 2,
        repeated_from_id: null,
        repeated_from_number: null,
        next_action: 'fill',
        can_edit: false,
        can_submit: false,
        can_approve: false,
        can_return: false,
        can_resubmit: false,
        can_hold: true,
        can_resume: false,
        can_extend: true,
        can_change_headcount: true,
        can_fill: true,
        can_cancel: true,
        can_reopen: false,
        can_repeat: false,
        notes: null,
        cancellation_reason: null,
        return_reason: null,
        opened_at_formatted: '05-01-2026 09:00',
        submitted_at_formatted: '04-01-2026 09:00',
        returned_at_formatted: null,
        completed_at_formatted: null,
        cancelled_at_formatted: null,
        created_at_formatted: '03-01-2026 09:00',
        creator_name: 'Requester User',
        updater_name: 'Requester User',
        submitter_name: 'Requester User',
        returner_name: null,
        notification_recipients: [],
        recruitment_started_at: '2026-01-05T09:00:00+04:00',
        recruitment_started_at_formatted: '05-01-2026 09:00',
        recruitment_start_source: 'approved_at',
        active_recruitment_seconds: 8 * 86400,
        active_recruitment_days: 8,
        on_hold_seconds: 0,
        recruitment_duration_label: '8 days',
        recruitment_clock_state: 'running',
        duration_is_estimated: false,
        duration_estimate_note: null,
        approved_at: '2026-01-05T09:00:00+04:00',
        approved_at_formatted: '05-01-2026 09:00',
        approved_by_name: 'Recruiter User',
        lines: [],
        attachments: [],
        progress: {
            filled: 0,
            target: 3,
            percentage: 0,
            is_target_reached: false,
        },
        ...overrides,
    };
}

const noop = () => undefined;

async function withViteModule<T>(
    relativePath: string,
    run: (mod: T) => Promise<void> | void,
): Promise<void> {
    const vite = await createServer({
        configFile: false,
        plugins: [(await import('@vitejs/plugin-react')).default()],
        resolve: {
            alias: {
                '@': new URL('../../../../../', import.meta.url).pathname,
            },
        },
    });

    try {
        const mod = (await vite.ssrLoadModule(relativePath)) as T;
        await run(mod);
    } finally {
        await vite.close();
    }
}

describe('Requirement detail cards', () => {
    it('removes Recruitment clock and shows Active recruitment in days with Target Date', async () => {
        await withViteModule<{
            RequirementDetailsCard: React.ComponentType<{
                requirement: RequirementDetail;
            }>;
        }>(
            './resources/js/features/organization/recruitment/requirements/components/show/requirement-details-card.tsx',
            ({ RequirementDetailsCard }) => {
                const html = renderToString(
                    React.createElement(RequirementDetailsCard, {
                        requirement: makeRequirement(),
                    }),
                );

                assert.ok(!html.includes('Recruitment clock'));
                assert.ok(html.includes('Active recruitment'));
                assert.ok(html.includes('8 days'));
                assert.ok(html.includes('Target Date'));
                assert.ok(!html.includes('Required-By Date'));
                assert.ok(!html.includes('Required by'));
            },
        );
    });

    it('shows paused and completed day phrasing on Active recruitment', async () => {
        await withViteModule<{
            RequirementDetailsCard: React.ComponentType<{
                requirement: RequirementDetail;
            }>;
        }>(
            './resources/js/features/organization/recruitment/requirements/components/show/requirement-details-card.tsx',
            ({ RequirementDetailsCard }) => {
                const pausedHtml = renderToString(
                    React.createElement(RequirementDetailsCard, {
                        requirement: makeRequirement({
                            status: 'on_hold',
                            recruitment_clock_state: 'paused',
                            active_recruitment_seconds: 8 * 86400,
                        }),
                    }),
                );
                assert.ok(pausedHtml.includes('Paused at 8 days'));

                const completedHtml = renderToString(
                    React.createElement(RequirementDetailsCard, {
                        requirement: makeRequirement({
                            status: 'completed',
                            recruitment_clock_state: 'completed',
                            active_recruitment_seconds: 14 * 86400,
                            can_fill: false,
                        }),
                    }),
                );
                assert.ok(completedHtml.includes('Completed in 14 days'));

                const shortHtml = renderToString(
                    React.createElement(RequirementDetailsCard, {
                        requirement: makeRequirement({
                            active_recruitment_seconds: 3 * 3600,
                        }),
                    }),
                );
                assert.ok(shortHtml.includes('Less than 1 day'));
                assert.ok(!shortHtml.includes('hour'));
            },
        );
    });

    it('renders one Mark as filled primary action in Status & actions', async () => {
        await withViteModule<{
            RequirementOverviewCard: React.ComponentType<{
                requirement: RequirementDetail;
                onEdit: () => void;
                onSubmit: () => void;
                onApprove: () => void;
                onReturn: () => void;
                onResubmit: () => void;
                onHold: () => void;
                onResume: () => void;
                onExtend: () => void;
                onChangeHeadcount: () => void;
                onFill: () => void;
                onCancel: () => void;
                onReopen: () => void;
                onRepeat: () => void;
            }>;
        }>(
            './resources/js/features/organization/recruitment/requirements/components/show/requirement-overview-card.tsx',
            ({ RequirementOverviewCard }) => {
                const html = renderToString(
                    React.createElement(RequirementOverviewCard, {
                        requirement: makeRequirement({ can_fill: true }),
                        onEdit: noop,
                        onSubmit: noop,
                        onApprove: noop,
                        onReturn: noop,
                        onResubmit: noop,
                        onHold: noop,
                        onResume: noop,
                        onExtend: noop,
                        onChangeHeadcount: noop,
                        onFill: noop,
                        onCancel: noop,
                        onReopen: noop,
                        onRepeat: noop,
                    }),
                );

                assert.ok(
                    html.includes('Status &amp; actions') ||
                        html.includes('Status & actions'),
                );
                assert.ok(html.includes('Target Date'));
                assert.ok(html.includes('Active recruitment'));
                assert.ok(html.includes('8 days'));
                assert.ok(html.includes('Mark as filled'));
                assert.equal((html.match(/Mark as filled/g) ?? []).length, 1);
                assert.ok(html.includes('data-primary-workflow-action="fill"'));
                assert.ok(!html.includes('What’s next?'));
                assert.ok(!html.includes("What's next?"));
            },
        );
    });

    it('does not render Mark as filled when fill permission is absent', async () => {
        await withViteModule<{
            RequirementOverviewCard: React.ComponentType<{
                requirement: RequirementDetail;
                onEdit: () => void;
                onSubmit: () => void;
                onApprove: () => void;
                onReturn: () => void;
                onResubmit: () => void;
                onHold: () => void;
                onResume: () => void;
                onExtend: () => void;
                onChangeHeadcount: () => void;
                onFill: () => void;
                onCancel: () => void;
                onReopen: () => void;
                onRepeat: () => void;
            }>;
        }>(
            './resources/js/features/organization/recruitment/requirements/components/show/requirement-overview-card.tsx',
            ({ RequirementOverviewCard }) => {
                const html = renderToString(
                    React.createElement(RequirementOverviewCard, {
                        requirement: makeRequirement({
                            can_fill: false,
                            can_approve: true,
                            next_action: 'approve',
                            status: 'pending_approval',
                            status_label: 'Pending approval',
                            recruitment_clock_state: 'not_started',
                            active_recruitment_seconds: null,
                        }),
                        onEdit: noop,
                        onSubmit: noop,
                        onApprove: noop,
                        onReturn: noop,
                        onResubmit: noop,
                        onHold: noop,
                        onResume: noop,
                        onExtend: noop,
                        onChangeHeadcount: noop,
                        onFill: noop,
                        onCancel: noop,
                        onReopen: noop,
                        onRepeat: noop,
                    }),
                );

                assert.ok(html.includes('Approve requirement'));
                assert.ok(!html.includes('Mark as filled'));
                assert.equal(
                    (html.match(/data-primary-workflow-action="/g) ?? [])
                        .length,
                    1,
                );
            },
        );
    });
});
