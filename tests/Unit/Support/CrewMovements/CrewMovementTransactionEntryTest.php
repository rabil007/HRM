<?php

use App\Support\CrewMovements\CrewMovementService;

test('movement perform resolves assignment identity before opening transaction', function () {
    $source = file_get_contents((new ReflectionClass(CrewMovementService::class))->getFileName());

    expect($source)->toContain('$identity = $this->resolveAssignmentIdentity($companyId, $assignmentId);')
        ->and($source)->toMatch('/resolveAssignmentIdentity\(\$companyId, \$assignmentId\);\s*\n\s*return DB::transaction/s');
});
