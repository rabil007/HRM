import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import React from 'react';
import { renderToString } from 'react-dom/server';
import { createServer } from 'vite';
import type {
    RequirementDetail,
    RequirementWorkflowTimeline,
} from '@/types/recruitment';

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
        notes: 'Mobilise ASAP',
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
        notification_recipients: [
            {
                id: 4,
                name: 'Ops Lead',
                email: 'ops@example.com',
            },
        ],
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

function makeTimeline(
    overrides: Partial<RequirementWorkflowTimeline> = {},
): RequirementWorkflowTimeline {
    return {
        current_stage: 'open',
        current_stage_label: 'Open',
        next_expected_action: 'fill',
        next_expected_action_label: 'Mark as filled',
        events: [
            {
                id: 'transition_1',
                key: 'submitted',
                label: 'Submitted for approval',
                occurred_at: '2026-01-04T09:00:00+04:00',
                occurred_at_formatted: '04-01-2026 09:00',
                actor_name: 'Requester User',
                reason: null,
                is_current: false,
                state: 'completed',
            },
            {
                id: 'transition_2',
                key: 'approved',
                label: 'Approved / recruitment started',
                occurred_at: '2026-01-05T09:00:00+04:00',
                occurred_at_formatted: '05-01-2026 09:00',
                actor_name: 'Recruiter User',
                reason: null,
                is_current: true,
                state: 'current',
            },
        ],
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
    it('renders unified Details & Workflow without duplicated lifecycle rows', async () => {
        await withViteModule<{
            RequirementDetailsWorkflowCard: React.ComponentType<{
                requirement: RequirementDetail;
                timeline: RequirementWorkflowTimeline;
            }>;
        }>(
            './resources/js/features/organization/recruitment/requirements/components/show/requirement-details-workflow-card.tsx',
            ({ RequirementDetailsWorkflowCard }) => {
                const html = renderToString(
                    React.createElement(RequirementDetailsWorkflowCard, {
                        requirement: makeRequirement(),
                        timeline: makeTimeline(),
                    }),
                );

                assert.ok(
                    html.includes('Requirement Details &amp; Workflow') ||
                        html.includes('Requirement Details & Workflow'),
                );
                assert.ok(html.includes('Request Received from Client'));
                assert.ok(!html.includes('Request Received Date'));
                assert.ok(html.includes('Target Date'));
                assert.ok(html.includes('Notification recipients (CC)'));
                assert.ok(html.includes('Notes / Scope of Work'));
                assert.ok(html.includes('Workflow timeline'));
                assert.ok(html.includes('Submitted for approval'));
                assert.ok(html.includes('Approved / recruitment started'));
                assert.ok(html.includes('data-requirement-details-section'));
                assert.ok(html.includes('data-requirement-workflow-section'));
                assert.ok(!html.includes('Opened Date'));
                assert.ok(!html.includes('Active recruitment'));
                assert.ok(!html.includes('Requirement Specifications'));
                assert.ok(!html.includes('Recruitment clock'));
            },
        );
    });

    it('keeps Active recruitment only in Status & actions', async () => {
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
                assert.ok(!html.includes('Put on hold'));
            },
        );
    });

    it('shows paused and completed day phrasing on Active recruitment in Status & actions', async () => {
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
                const pausedHtml = renderToString(
                    React.createElement(RequirementOverviewCard, {
                        requirement: makeRequirement({
                            status: 'on_hold',
                            recruitment_clock_state: 'paused',
                            active_recruitment_seconds: 8 * 86400,
                            can_fill: false,
                            can_resume: true,
                            next_action: 'resume',
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
                assert.ok(pausedHtml.includes('Paused at 8 days'));

                const completedHtml = renderToString(
                    React.createElement(RequirementOverviewCard, {
                        requirement: makeRequirement({
                            status: 'completed',
                            recruitment_clock_state: 'completed',
                            active_recruitment_seconds: 14 * 86400,
                            can_fill: false,
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
                assert.ok(completedHtml.includes('Completed in 14 days'));
            },
        );
    });

    it('keeps Resume for existing On Hold requirements only', async () => {
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
                const onHoldHtml = renderToString(
                    React.createElement(RequirementOverviewCard, {
                        requirement: makeRequirement({
                            status: 'on_hold',
                            status_label: 'On Hold',
                            can_hold: false,
                            can_resume: true,
                            can_fill: true,
                            recruitment_clock_state: 'paused',
                            active_recruitment_seconds: 4 * 86400,
                            next_action: 'resume',
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

                assert.ok(onHoldHtml.includes('Resume requirement'));
                assert.ok(!onHoldHtml.includes('Put on hold'));
                assert.ok(
                    onHoldHtml.includes(
                        'data-primary-workflow-action="resume"',
                    ),
                );
                assert.ok(onHoldHtml.includes('Paused at 4 days'));
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
