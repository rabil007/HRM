import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    clamp,
    normalizedToPixel,
    pixelToNormalized,
    placementRectInVisibleCanvas,
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

describe('placementRectInVisibleCanvas', () => {
    const canvas = { top: 0, left: 100, width: 800, height: 2000, bottom: 2000, right: 900 };
    const view = { top: 0, left: 0, width: 1000, height: 600, bottom: 600, right: 1000 };

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
            canvasRect: { top: 80, left: 100, width: 800, height: 500, bottom: 580, right: 900 },
            viewRect: { top: 0, left: 0, width: 1000, height: 800, bottom: 800, right: 1000 },
        });

        assert.equal(pixel.left, 320);
        assert.equal(pixel.top, 237);
    });
});
