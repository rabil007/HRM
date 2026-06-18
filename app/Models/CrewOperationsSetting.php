<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrewOperationsSetting extends Model
{
    protected $table = 'crew_operations_settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'pool_department_ids' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
