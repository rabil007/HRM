import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    isInteractiveKanbanTarget,
    shouldNavigateKanbanCardClick,
} from './kanban-card-interaction.ts';

describe('kanban-card-interaction', () => {
    it('treats closest-matching interactive descendants specially', () => {
        const buttonLike = {
            closest: (selector: string) =>
                selector.includes('button') ? {} : null,
        };
        const plain = {
            closest: () => null,
        };

        assert.equal(isInteractiveKanbanTarget(buttonLike), true);
        assert.equal(isInteractiveKanbanTarget(plain), false);
        assert.equal(isInteractiveKanbanTarget(null), false);
    });

    it('blocks navigation after drag or on interactive targets', () => {
        const buttonLike = {
            closest: () => ({}),
        };
        const plain = {
            closest: () => null,
        };

        assert.equal(
            shouldNavigateKanbanCardClick({
                isDragging: false,
                didDrag: false,
                target: plain,
            }),
            true,
        );
        assert.equal(
            shouldNavigateKanbanCardClick({
                isDragging: true,
                didDrag: false,
                target: plain,
            }),
            false,
        );
        assert.equal(
            shouldNavigateKanbanCardClick({
                isDragging: false,
                didDrag: true,
                target: plain,
            }),
            false,
        );
        assert.equal(
            shouldNavigateKanbanCardClick({
                isDragging: false,
                didDrag: false,
                target: buttonLike,
            }),
            false,
        );
    });
});
