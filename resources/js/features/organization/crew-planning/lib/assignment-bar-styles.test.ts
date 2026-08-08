import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    barAvatarClass,
    barResizeHandleClass,
    barSurfaceClass,
    deployedBarSurfaceClass,
    plannedBarSurfaceClass,
    plannedReliefBarSurfaceClass,
    resolveBarKind,
    vacantBarSurfaceClass,
} from './assignment-bar-styles.ts';

describe('assignment-bar-styles visual taxonomy', () => {
    it('resolves assignment_created bar to assigned (emerald) styling', () => {
        const input = {
            employee_id: 1,
            is_assigned: true,
            planning_kind: 'assignment_created' as const,
        };

        assert.equal(resolveBarKind(input), 'assignment_created');
        assert.equal(barSurfaceClass(input), deployedBarSurfaceClass);
        assert.match(barSurfaceClass(input), /emerald/);
        assert.match(barAvatarClass(input), /emerald/);
        assert.match(barResizeHandleClass(input), /emerald/);
    });

    it('resolves planned_relief bar to relief (sky) styling', () => {
        const input = {
            employee_id: 2,
            is_assigned: false,
            planning_kind: 'planned_relief' as const,
            relieves_crew_assignment_id: 10,
        };

        assert.equal(resolveBarKind(input), 'planned_relief');
        assert.equal(barSurfaceClass(input), plannedReliefBarSurfaceClass);
        assert.match(barSurfaceClass(input), /sky/);
        assert.match(barAvatarClass(input), /sky/);
        assert.match(barResizeHandleClass(input), /sky/);
    });

    it('resolves planned bar to distinct planned (indigo) styling', () => {
        const input = {
            employee_id: 3,
            is_assigned: false,
            planning_kind: 'planned' as const,
        };

        assert.equal(resolveBarKind(input), 'planned');
        assert.equal(barSurfaceClass(input), plannedBarSurfaceClass);
        assert.match(barSurfaceClass(input), /indigo/);
        assert.match(barAvatarClass(input), /indigo/);
        assert.match(barResizeHandleClass(input), /indigo/);
    });

    it('ensures planned and planned_relief are not visually identical', () => {
        const plannedInput = {
            employee_id: 3,
            is_assigned: false,
            planning_kind: 'planned' as const,
        };
        const reliefInput = {
            employee_id: 2,
            is_assigned: false,
            planning_kind: 'planned_relief' as const,
        };

        assert.notEqual(
            barSurfaceClass(plannedInput),
            barSurfaceClass(reliefInput),
        );
        assert.notEqual(
            barAvatarClass(plannedInput),
            barAvatarClass(reliefInput),
        );
        assert.notEqual(
            barResizeHandleClass(plannedInput),
            barResizeHandleClass(reliefInput),
        );
    });

    it('preserves vacant rendering behavior', () => {
        const input = {
            employee_id: null,
            is_assigned: false,
            planning_kind: 'vacant_slot' as const,
        };

        assert.equal(resolveBarKind(input), 'vacant_slot');
        assert.equal(barSurfaceClass(input), vacantBarSurfaceClass);
        assert.match(barSurfaceClass(input), /dashed/);
    });
});
