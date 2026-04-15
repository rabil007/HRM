<?php

namespace App\Models\Concerns;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

trait LogsActivityWithCompany
{
    use LogsActivity;

    public function beforeActivityLogged(Activity $activity, string $eventName): void
    {
        $companyId = null;

        if ($this instanceof Model && array_key_exists('company_id', $this->getAttributes())) {
            $companyId = $this->getAttribute('company_id');
        }

        if (! $companyId) {
            $companyId = request()->attributes->get('current_company_id');
        }

        if (! $companyId && $this instanceof Model && $this::class === Company::class) {
            $companyId = $this->getKey();
        }

        $activity->company_id = $companyId ? (int) $companyId : null;
    }
}
