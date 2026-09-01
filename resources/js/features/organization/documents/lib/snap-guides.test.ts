import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { snapRectToGuides } from '../templates/lib/snap-guides.ts';

describe('snapRectToGuides', () => {
    it('does not snap left/right — only the Y axis', () => {
        const result = snapRectToGuides(
            { left: 104, top: 50, width: 80, height: 24 },
            [{ left: 100, top: 200, width: 80, height: 24 }],
            800,
            1000,
            6,
        );

        assert.equal(result.left, 104);
        assert.equal(result.top, 50);
        assert.equal(result.guides.length, 0);
    });

    it('snaps the middle of the box to the page vertical center', () => {
        const result = snapRectToGuides(
            { left: 40, top: 487, width: 80, height: 20 },
            [],
            800,
            1000,
            6,
        );

        assert.equal(result.top, 490);
        assert.ok(
            result.guides.some(
                (guide) => guide.axis === 'y' && guide.position === 500,
            ),
        );
    });

    it('does not snap when the vertical gap is larger than the threshold', () => {
        const result = snapRectToGuides(
            { left: 20, top: 50, width: 80, height: 24 },
            [{ left: 200, top: 200, width: 90, height: 24 }],
            800,
            1000,
            6,
        );

        assert.equal(result.top, 50);
        assert.equal(result.guides.length, 0);
    });

    it('picks the closer of two nearby baselines', () => {
        const result = snapRectToGuides(
            { left: 20, top: 104, width: 80, height: 24 },
            [
                { left: 200, top: 100, width: 80, height: 24 },
                { left: 200, top: 111, width: 80, height: 24 },
            ],
            800,
            1000,
            6,
        );

        assert.equal(result.top, 100);
    });

    it('snaps the baseline to another box bottom', () => {
        const result = snapRectToGuides(
            { left: 20, top: 198, width: 80, height: 24 },
            [{ left: 200, top: 200, width: 90, height: 24 }],
            800,
            1000,
            6,
        );

        assert.equal(result.top, 200);
        assert.ok(
            result.guides.some(
                (guide) => guide.axis === 'y' && guide.position === 224,
            ),
        );
    });
});
