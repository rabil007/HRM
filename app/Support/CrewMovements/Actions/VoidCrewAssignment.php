<?php

namespace App\Support\CrewMovements\Actions;

use App\Models\CrewAssignment;
use App\Models\User;

final class VoidCrewAssignment
{
    public function __construct(
        private readonly BulkVoidCrewAssignments $bulkVoid,
    ) {}

    public function handle(
        int $companyId,
        int $assignmentId,
        User $actor,
        string $reason,
        bool $deleteSeaService = false,
        bool $deleteTraining = false,
    ): CrewAssignment {
        return $this->bulkVoid->handle(
            companyId: $companyId,
            assignmentIds: [$assignmentId],
            actor: $actor,
            reason: $reason,
            deleteSeaService: $deleteSeaService,
            deleteTraining: $deleteTraining,
        )->first();
    }
}
