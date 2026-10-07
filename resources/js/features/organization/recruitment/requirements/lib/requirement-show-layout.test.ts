import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { describe, it } from 'node:test';
import { fileURLToPath } from 'node:url';

const showContentPath = fileURLToPath(
    new URL('../requirements-show-content.tsx', import.meta.url),
);

describe('requirement show layout structure', () => {
    const source = readFileSync(showContentPath, 'utf8');

    it('makes the sidebar sticky on desktop only and keeps mobile document flow', () => {
        assert.match(source, /data-requirement-sidebar/);
        assert.match(source, /lg:sticky/);
        assert.match(source, /lg:top-4/);
        assert.match(source, /lg:max-h-\[calc\(100vh-2rem\)\]/);
        assert.match(source, /lg:overflow-y-auto/);
        assert.ok(!source.includes('fixed top-'));
    });

    it('places Recent Activity in the main column and timeline in the sidebar', () => {
        assert.match(source, /data-requirement-main-column/);
        assert.match(source, /RequirementWorkflowTimelineCard/);
        assert.match(source, /RecentActivityCard/);

        const sidebarIndex = source.indexOf('data-requirement-sidebar');
        const timelineRenderIndex = source.indexOf(
            '<RequirementWorkflowTimelineCard',
        );
        const mainIndex = source.indexOf('data-requirement-main-column');
        const recentRenderIndex = source.indexOf('<RecentActivityCard');

        assert.ok(sidebarIndex > -1 && timelineRenderIndex > sidebarIndex);
        assert.ok(mainIndex > -1 && recentRenderIndex > mainIndex);
        assert.ok(timelineRenderIndex < recentRenderIndex);
    });
});
