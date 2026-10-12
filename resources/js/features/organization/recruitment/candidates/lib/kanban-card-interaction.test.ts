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

    it('treats keyboard drag handles as interactive so Enter does not navigate', () => {
        const dragHandle = {
            closest: (selector: string) =>
                selector.includes('[data-kanban-interactive="true"]')
                    ? {}
                    : null,
        };

        assert.equal(isInteractiveKanbanTarget(dragHandle), true);
        assert.equal(
            shouldNavigateKanbanCardClick({
                isDragging: false,
                didDrag: false,
                target: dragHandle,
            }),
            false,
        );
    });

    it('allows plain card body clicks to navigate after distinguishing from drag', () => {
        const cardBody = {
            closest: () => null,
        };

        assert.equal(
            shouldNavigateKanbanCardClick({
                isDragging: false,
                didDrag: false,
                target: cardBody,
            }),
            true,
        );
        assert.equal(
            shouldNavigateKanbanCardClick({
                isDragging: false,
                didDrag: true,
                target: cardBody,
            }),
            false,
        );
    });
});
