import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    clamp,
    normalizedToPixel,
    pixelToNormalized,
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
