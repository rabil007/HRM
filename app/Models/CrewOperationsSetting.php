<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrewOperationsSetting extends Model
{
    use SoftDeletes;

    protected $table = 'crew_operations_settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'pool_department_ids' => 'array',
            'max_home_days' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
