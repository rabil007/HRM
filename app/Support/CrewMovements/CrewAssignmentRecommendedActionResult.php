<?php

namespace App\Support\CrewMovements;

/**
 * Advisory next-step guidance. Never replaces CrewMovementAvailableActions.
 *
 * @phpstan-type RecommendedActionArray array{
 *     type: string,
 *     action: string|null,
 *     label: string,
 *     reason: string,
 *     href: string|null,
 *     anyway_action: string|null,
 *     anyway_label: string|null
 * }
 */
final class CrewAssignmentRecommendedActionResult
{
    public function __construct(
        public readonly string $type,
        public readonly string $label,
        public readonly string $reason,
        public readonly ?string $action = null,
        public readonly ?string $href = null,
        public readonly ?string $anywayAction = null,
        public readonly ?string $anywayLabel = null,
    ) {}

    /**
     * @return RecommendedActionArray
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'action' => $this->action,
            'label' => $this->label,
            'reason' => $this->reason,
            'href' => $this->href,
            'anyway_action' => $this->anywayAction,
            'anyway_label' => $this->anywayLabel,
        ];
    }
}
