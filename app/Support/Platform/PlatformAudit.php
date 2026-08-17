<?php

namespace App\Support\Platform;

use App\Models\User;
use Spatie\Activitylog\Models\Activity;

final class PlatformAudit
{
    /**
     * Record a platform administration event without logging secrets or result bodies.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function record(?User $user, string $description, array $properties = []): void
    {
        $request = request();
        $companyId = $request->attributes->get('current_company_id');

        activity('platform')
            ->causedBy($user)
            ->withProperties([
                'scope' => 'platform',
                ...$properties,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ])
            ->tap(function (Activity $activity) use ($companyId): void {
                $activity->company_id = $companyId ? (int) $companyId : null;
            })
            ->log($description);
    }
}
