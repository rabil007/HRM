<?php

namespace App\Support\CrewMovements;

use Illuminate\Http\Request;

final class CurrentCrewOnboardExportScope
{
    public const ALL = 'all';

    public const SELECTED = 'selected';

    public static function fromRequest(Request $request): string
    {
        $scope = strtolower(trim((string) $request->query('scope', '')));

        if ($scope === self::ALL || $scope === self::SELECTED) {
            return $scope;
        }

        return CurrentCrewVesselQuery::sanitizeAssignmentIds($request->query('assignment_ids', [])) !== []
            ? self::SELECTED
            : self::ALL;
    }
}
