<?php

namespace App\Models;

use App\Enums\RankPositionMatchType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RankPositionMapping extends Model
{
    protected $fillable = [
        'company_id',
        'rank_id',
        'position_id',
        'match_type',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'rank_id' => 'integer',
            'position_id' => 'integer',
            'match_type' => RankPositionMatchType::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function rank(): BelongsTo
    {
        return $this->belongsTo(Rank::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }
}
