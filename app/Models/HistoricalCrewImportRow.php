<?php

namespace App\Models;

use App\Enums\HistoricalCrewImportRowStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoricalCrewImportRow extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => HistoricalCrewImportRowStatus::class,
            'row_number' => 'integer',
            'warnings' => 'array',
            'errors' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(HistoricalCrewImportBatch::class, 'historical_crew_import_batch_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(CrewAssignment::class, 'crew_assignment_id');
    }
}
