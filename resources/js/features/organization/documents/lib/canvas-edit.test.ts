import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    clickToAlignedPlacement,
    clickToCenteredPlacement,
    cloneDesignState,
    DesignHistory,
    isRedoKey,
    isUndoKey,
    nudgeDeltaFromKeyboard,
    nudgeNormalizedPlacement,
    overlayFieldLabelLayout,
    overlayTextTopForAlign,
} from '../templates/lib/canvas-edit.ts';

describe('clickToCenteredPlacement', () => {
    it('centers the box on the click and keeps it on the page', () => {
        const pixel = clickToCenteredPlacement(100, 80, 160, 26, 800, 1000);

        assert.equal(pixel.left, 20);
        assert.equal(pixel.top, 67);
        assert.equal(pixel.width, 160);
        assert.equal(pixel.height, 26);
    });

    it('clamps a click near the page edge', () => {
        const pixel = clickToCenteredPlacement(10, 10, 160, 26, 800, 1000);

        assert.equal(pixel.left, 0);
        assert.equal(pixel.top, 0);
    });
});

describe('clickToAlignedPlacement', () => {
    it('hangs the box above a baseline click', () => {
        const pixel = clickToAlignedPlacement(
            100,
            80,
            160,
            26,
            800,
            1000,
            'baseline',
        );

        assert.equal(pixel.left, 20);
        assert.equal(pixel.top, 54);
        assert.equal(pixel.height, 26);
    });

    it('clamps a baseline click near the top edge', () => {
        const pixel = clickToAlignedPlacement(
            100,
            10,
            160,
            26,
            800,
            1000,
            'baseline',
        );

        assert.equal(pixel.top, 0);
    });
});

describe('overlayTextTopForAlign', () => {
    it('pins baseline values to the box floor like generated flex-end', () => {
        assert.equal(overlayTextTopForAlign(100, 40, 14, 'baseline'), 126);
        assert.equal(overlayTextTopForAlign(100, 40, 14, 'top'), 100);
        assert.equal(overlayTextTopForAlign(100, 40, 14, 'middle'), 113);
    });
});

describe('overlayFieldLabelLayout', () => {
    it('uses the geometric left edge with no inset so generate can match', () => {
        const layout = overlayFieldLabelLayout(
            20,
            100,
            200,
            40,
            'left',
            'baseline',
            14,
        );

        assert.equal(layout.left, 20);
        assert.equal(layout.top, 126);
        assert.equal(layout.originX, 'left');
        assert.equal(layout.originY, 'top');
    });

    it('places right-aligned text on the box right edge', () => {
        const layout = overlayFieldLabelLayout(
            20,
            100,
            200,
            40,
            'right',
            'top',
            14,
        );

        assert.equal(layout.left, 220);
        assert.equal(layout.originX, 'right');
        assert.equal(layout.top, 100);
    });
});

describe('nudgeNormalizedPlacement', () => {
    it('moves one canvas pixel in normalized space', () => {
        const next = nudgeNormalizedPlacement(
            { x: 0.2, y: 0.3, width: 0.2, height: 0.05 },
            8,
            -10,
            800,
            1000,
        );

        assert.equal(next.x, 0.21);
        assert.equal(next.y, 0.29);
        assert.equal(next.width, 0.2);
        assert.equal(next.height, 0.05);
    });

    it('does not move the box off the page', () => {
        const next = nudgeNormalizedPlacement(
            { x: 0.9, y: 0, width: 0.1, height: 0.05 },
            40,
            -10,
            800,
            1000,
        );

        assert.equal(next.x, 0.9);
        assert.equal(next.y, 0);
    });
});

describe('nudgeDeltaFromKeyboard', () => {
    it('uses 1px arrows and 10px with shift', () => {
        assert.deepEqual(
            nudgeDeltaFromKeyboard({
                key: 'ArrowLeft',
                shiftKey: false,
                metaKey: false,
                ctrlKey: false,
                altKey: false,
            } as KeyboardEvent),
            { dx: -1, dy: 0 },
        );
        assert.deepEqual(
            nudgeDeltaFromKeyboard({
                key: 'ArrowDown',
                shiftKey: true,
                metaKey: false,
                ctrlKey: false,
                altKey: false,
            } as KeyboardEvent),
            { dx: 0, dy: 10 },
        );
    });
});

describe('DesignHistory', () => {
    it('undoes and redoes a cloned snapshot', () => {
        const history = new DesignHistory<{ n: number }, { s: number }>();
        const first = { placements: { n: 1 }, signaturePlacements: { s: 1 } };
        history.push(first);

        first.placements.n = 2;
        const undone = history.undo({
            placements: { n: 2 },
            signaturePlacements: { s: 1 },
        });

        assert.deepEqual(undone, {
            placements: { n: 1 },
            signaturePlacements: { s: 1 },
        });

        const redone = history.redo(undone!);
        assert.deepEqual(redone, {
            placements: { n: 2 },
            signaturePlacements: { s: 1 },
        });
    });

    it('cloneDesignState does not share references', () => {
        const original = {
            placements: [{ id: 'a' }],
            signaturePlacements: { subject: { id: 's' } },
        };
        const cloned = cloneDesignState(original);
        original.placements[0]!.id = 'b';

        assert.equal(cloned.placements[0]?.id, 'a');
    });
});

describe('undo redo keys', () => {
    it('recognizes common shortcuts', () => {
        assert.equal(
            isUndoKey({
                key: 'z',
                metaKey: true,
                ctrlKey: false,
                shiftKey: false,
                altKey: false,
            } as KeyboardEvent),
            true,
        );
        assert.equal(
            isRedoKey({
                key: 'z',
                metaKey: true,
                ctrlKey: false,
                shiftKey: true,
                altKey: false,
            } as KeyboardEvent),
            true,
        );
        assert.equal(
            isRedoKey({
                key: 'y',
                metaKey: false,
                ctrlKey: true,
                shiftKey: false,
                altKey: false,
            } as KeyboardEvent),
            true,
        );
    });
});
