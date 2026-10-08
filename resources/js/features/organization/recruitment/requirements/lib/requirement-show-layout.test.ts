import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { describe, it } from 'node:test';
import { fileURLToPath } from 'node:url';

const showContentPath = fileURLToPath(
    new URL('../requirements-show-content.tsx', import.meta.url),
);
const formSheetPath = fileURLToPath(
    new URL('../components/requirement-form-sheet.tsx', import.meta.url),
);
const unifiedCardPath = fileURLToPath(
    new URL(
        '../components/show/requirement-details-workflow-card.tsx',
        import.meta.url,
    ),
);

describe('requirement show layout structure', () => {
    const source = readFileSync(showContentPath, 'utf8');
    const formSource = readFileSync(formSheetPath, 'utf8');
    const unifiedSource = readFileSync(unifiedCardPath, 'utf8');

    it('makes the sidebar sticky on desktop only and keeps mobile document flow', () => {
        assert.match(source, /data-requirement-sidebar/);
        assert.match(source, /lg:sticky/);
        assert.match(source, /lg:top-4/);
        assert.match(source, /lg:max-h-\[calc\(100vh-2rem\)\]/);
        assert.match(source, /lg:overflow-y-auto/);
        assert.ok(!source.includes('fixed top-'));
    });

    it('keeps Status & Actions with workflow timeline in the sticky sidebar', () => {
        assert.match(source, /RequirementOverviewCard/);
        assert.match(source, /timeline=\{workflow_timeline\}/);
        assert.ok(!source.includes('RequirementWorkflowTimelineCard'));
        assert.ok(!source.includes('RequirementDetailsCard'));

        const sidebarIndex = source.indexOf('data-requirement-sidebar');
        const overviewIndex = source.indexOf('<RequirementOverviewCard');
        const mainIndex = source.indexOf('data-requirement-main-column');

        assert.ok(sidebarIndex > -1 && overviewIndex > sidebarIndex);
        assert.ok(overviewIndex < mainIndex);
    });

    it('places Requirement Details and Recent Activity in the main column', () => {
        assert.match(source, /data-requirement-main-column/);
        assert.match(source, /RequirementDetailsWorkflowCard/);
        assert.match(source, /RecentActivityCard/);
        assert.match(source, /RequirementPositionLinesCard/);

        const mainIndex = source.indexOf('data-requirement-main-column');
        const unifiedIndex = source.indexOf('<RequirementDetailsWorkflowCard');
        const recentIndex = source.indexOf('<RecentActivityCard');
        const positionsIndex = source.indexOf('<RequirementPositionLinesCard');

        assert.ok(mainIndex > -1 && positionsIndex > mainIndex);
        assert.ok(unifiedIndex > positionsIndex);
        assert.ok(recentIndex > unifiedIndex);
        assert.ok(
            !source
                .slice(unifiedIndex, unifiedIndex + 200)
                .includes('timeline='),
        );
    });

    it('uses creatable client/project selects with permission-aware hooks', () => {
        assert.match(formSource, /CreatableSelect/);
        assert.match(formSource, /useCreatableMasterData\('client'\)/);
        assert.match(
            formSource,
            /useCreatableMasterData\('project',\s*\{\s*clientId:/,
        );
        assert.match(formSource, /Select a client first/);
        assert.match(formSource, /Request Received from Client/);
        assert.ok(!formSource.includes('Request Received Date'));
        assert.match(formSource, /data-requirement-field="client_id"/);
        assert.match(formSource, /data-requirement-field="project_id"/);
        assert.match(formSource, /data-requirement-field="assigned_to"/);
        assert.match(
            formSource,
            /data-requirement-field=\{`positions\.\$\{index\}\.position_id`\}/,
        );
        assert.ok(!formSource.includes('Submission readiness'));
        assert.ok(
            formSource.indexOf('<CreatableSelect') <
                formSource.indexOf('data-requirement-field="project_id"'),
        );
    });

    it('keeps Requirement Details as a single-column details card', () => {
        assert.match(unifiedSource, /data-requirement-details-section/);
        assert.ok(!unifiedSource.includes('data-requirement-workflow-section'));
        assert.ok(!unifiedSource.includes('lg:grid-cols-2'));
        assert.match(unifiedSource, /Requirement Details/);
        assert.ok(!unifiedSource.includes('Requirement Details & Workflow'));
        assert.match(unifiedSource, /Request Received from Client/);
        assert.match(unifiedSource, /Client request/);
        assert.match(unifiedSource, /Schedule & ownership/);
        assert.match(unifiedSource, /Days remaining/);
        assert.match(unifiedSource, /Active recruitment/);
        assert.match(unifiedSource, /data-requirement-schedule-metrics/);
        assert.match(unifiedSource, /Countdown to target date/);
        assert.match(unifiedSource, /Record info/);
        assert.ok(!unifiedSource.includes('Opened Date'));
        assert.ok(!unifiedSource.includes('Requirement Specifications'));
        assert.ok(!unifiedSource.includes('Current stage'));
        assert.ok(!unifiedSource.includes('Next expected action'));
    });
});
