<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bank extends Model
{
    protected $guarded = [];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
