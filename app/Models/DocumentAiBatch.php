<?php

namespace App\Models;

use App\Enums\DocumentAiBatchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentAiBatch extends Model
{
    protected $fillable = ['company_id', 'user_id', 'employee_id', 'status', 'total_items', 'completed_items', 'failed_items', 'expires_at'];

    protected function casts(): array
    {
        return ['status' => DocumentAiBatchStatus::class, 'expires_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(DocumentAiBatchItem::class);
    }
}
