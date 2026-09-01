import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    clamp,
    DEFAULT_OVERLAY_PLACEMENT_HEIGHT_PX,
    DEFAULT_OVERLAY_PLACEMENT_WIDTH_PX,
    normalizedToPixel,
    overlayFontSizePx,
    overlayFontSizeSelectOptionsPt,
    overlayPlacementBoxChrome,
    overlayPlacementChrome,
    overlayPlacementTextFill,
    pixelToNormalized,
    placementRectInVisibleCanvas,
    stepOverlayFontSizePt,
    fabricObjectToPixelRect,
} from '../templates/lib/coordinates.ts';

describe('coordinates conversion', () => {
    it('correctly normalizes pixel coordinates to [0, 1] range', () => {
        const canvasWidth = 800;
        const canvasHeight = 1000;

        const pixel = { left: 80, top: 100, width: 200, height: 50 };
        const normalized = pixelToNormalized(pixel, canvasWidth, canvasHeight);

        assert.equal(normalized.x, 0.1);
        assert.equal(normalized.y, 0.1);
        assert.equal(normalized.width, 0.25);
        assert.equal(normalized.height, 0.05);
    });

    it('clamps normalized coordinates within boundaries', () => {
        const canvasWidth = 500;
        const canvasHeight = 500;

        const pixel = { left: -50, top: 600, width: 700, height: 200 };
        const normalized = pixelToNormalized(pixel, canvasWidth, canvasHeight);

        assert.equal(normalized.x, 0);
        assert.equal(normalized.y, 1);
        assert.ok(normalized.width <= 1);
        assert.ok(normalized.height <= 1);
    });

    it('converts normalized coordinates back to canvas pixels accurately', () => {
        const canvasWidth = 600;
        const canvasHeight = 800;

        const normalized = { x: 0.2, y: 0.5, width: 0.3, height: 0.1 };
        const pixel = normalizedToPixel(normalized, canvasWidth, canvasHeight);

        assert.equal(pixel.left, 120);
        assert.equal(pixel.top, 400);
        assert.equal(pixel.width, 180);
        assert.equal(pixel.height, 80);
    });

    it('clamp helper restricts values properly', () => {
        assert.equal(clamp(15, 0, 10), 10);
        assert.equal(clamp(-5, 0, 10), 0);
        assert.equal(clamp(5, 0, 10), 5);
    });
});

describe('fabricObjectToPixelRect', () => {
    it('uses left/top/width/height without inventing a stroke inset', () => {
        assert.deepEqual(
            fabricObjectToPixelRect({
                left: 100,
                top: 200,
                width: 160,
                height: 50,
                scaleX: 1,
                scaleY: 1,
                originX: 'left',
                originY: 'top',
            }),
            { left: 100, top: 200, width: 160, height: 50 },
        );
    });

    it('bakes scale into the saved box', () => {
        assert.deepEqual(
            fabricObjectToPixelRect({
                left: 10,
                top: 20,
                width: 100,
                height: 40,
                scaleX: 2,
                scaleY: 0.5,
                originX: 'left',
                originY: 'top',
            }),
            { left: 10, top: 20, width: 200, height: 20 },
        );
    });

    it('converts a center origin to a top-left box', () => {
        assert.deepEqual(
            fabricObjectToPixelRect({
                left: 80,
                top: 60,
                width: 40,
                height: 20,
                scaleX: 1,
                scaleY: 1,
                originX: 'center',
                originY: 'center',
            }),
            { left: 60, top: 50, width: 40, height: 20 },
        );
    });

    it('treats a numeric origin as top-left', () => {
        assert.deepEqual(
            fabricObjectToPixelRect({
                left: 100,
                top: 200,
                width: 160,
                height: 50,
                scaleX: 1,
                scaleY: 1,
                originX: 0,
                originY: 0,
            }),
            { left: 100, top: 200, width: 160, height: 50 },
        );
    });
});

describe('overlayFontSizePx', () => {
    it('maps stored PDF points through the PDF.js viewport scale', () => {
        assert.equal(overlayFontSizePx(12, 1.5), 18);
        assert.equal(overlayFontSizePx(10, 1), 10);
    });

    it('defaults missing or invalid sizes and scales to 12pt at scale 1', () => {
        assert.equal(overlayFontSizePx(undefined, 1), 12);
        assert.equal(overlayFontSizePx(0, 2), 24);
        assert.equal(overlayFontSizePx(12, 0), 12);
        assert.equal(overlayFontSizePx(12, -1), 12);
    });
});

describe('stepOverlayFontSizePt', () => {
    it('steps one point and stops at 8 and 48', () => {
        assert.equal(stepOverlayFontSizePt(12, 1), 13);
        assert.equal(stepOverlayFontSizePt(12, -1), 11);
        assert.equal(stepOverlayFontSizePt(8, -1), 8);
        assert.equal(stepOverlayFontSizePt(48, 1), 48);
    });

    it('includes a non-preset size in the selector list', () => {
        assert.deepEqual(overlayFontSizeSelectOptionsPt(13).includes(13), true);
        assert.equal(overlayFontSizeSelectOptionsPt(12).includes(13), false);
    });
});

describe('overlay placement chrome', () => {
    it('uses the same default box for merge fields and static text', () => {
        assert.equal(DEFAULT_OVERLAY_PLACEMENT_WIDTH_PX, 160);
        assert.equal(DEFAULT_OVERLAY_PLACEMENT_HEIGHT_PX, 26);
    });

    it('hides box chrome in print preview and keeps the saved text color', () => {
        assert.deepEqual(overlayPlacementChrome('edit'), {
            fill: 'rgba(59,130,246,0.08)',
            stroke: '#93c5fd',
        });
        assert.deepEqual(overlayPlacementChrome('print'), {
            fill: 'transparent',
            stroke: 'transparent',
        });
        assert.equal(overlayPlacementTextFill('print', '#000000'), '#000000');
        assert.equal(overlayPlacementTextFill('edit', '#1e3a8a'), '#1e3a8a');
    });

    it('paints only boxes that cannot fit even at 8pt', () => {
        assert.deepEqual(overlayPlacementBoxChrome('edit', 'fail'), {
            fill: 'rgba(220,38,38,0.22)',
            stroke: '#dc2626',
            strokeWidth: 2.5,
        });
        assert.deepEqual(overlayPlacementBoxChrome('edit', 'shrink'), {
            fill: 'rgba(59,130,246,0.08)',
            stroke: '#93c5fd',
            strokeWidth: 1,
        });
        assert.deepEqual(overlayPlacementBoxChrome('edit', 'ok'), {
            fill: 'rgba(59,130,246,0.08)',
            stroke: '#93c5fd',
            strokeWidth: 1,
        });
    });
});

describe('placementRectInVisibleCanvas', () => {
    const canvas = {
        top: 0,
        left: 100,
        width: 800,
        height: 2000,
        bottom: 2000,
        right: 900,
    };
    const view = {
        top: 0,
        left: 0,
        width: 1000,
        height: 600,
        bottom: 600,
        right: 1000,
    };

    it('places the box in the middle of the visible viewport near the top', () => {
        const pixel = placementRectInVisibleCanvas({
            canvasWidth: 800,
            canvasHeight: 2000,
            boxWidth: 160,
            boxHeight: 26,
            canvasRect: canvas,
            viewRect: view,
        });

        assert.equal(pixel.left, 320);
        assert.equal(pixel.top, 287);
        assert.equal(pixel.width, 160);
        assert.equal(pixel.height, 26);
    });

    it('places the box in the visible bottom section when scrolled down', () => {
        const pixel = placementRectInVisibleCanvas({
            canvasWidth: 800,
            canvasHeight: 2000,
            boxWidth: 160,
            boxHeight: 26,
            canvasRect: { ...canvas, top: -1400, bottom: 600 },
            viewRect: view,
        });

        assert.equal(pixel.left, 320);
        assert.equal(pixel.top, 1687);
    });

    it('keeps the box on the canvas when the full page is visible', () => {
        const pixel = placementRectInVisibleCanvas({
            canvasWidth: 800,
            canvasHeight: 500,
            boxWidth: 160,
            boxHeight: 26,
            canvasRect: {
                top: 80,
                left: 100,
                width: 800,
                height: 500,
                bottom: 580,
                right: 900,
            },
            viewRect: {
                top: 0,
                left: 0,
                width: 1000,
                height: 800,
                bottom: 800,
                right: 1000,
            },
        });

        assert.equal(pixel.left, 320);
        assert.equal(pixel.top, 237);
    });
});
