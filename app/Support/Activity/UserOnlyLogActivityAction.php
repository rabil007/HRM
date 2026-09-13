<?php

namespace App\Support\Activity;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Actions\LogActivityAction;

final class UserOnlyLogActivityAction extends LogActivityAction
{
    protected function save(Model $activity): void
    {
        if (
            $activity->getAttribute('causer_type') !== User::class
            || $activity->getAttribute('causer_id') === null
        ) {
            return;
        }

        parent::save($activity);
    }
}
