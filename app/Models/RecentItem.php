<?php

namespace App\Models;

use App\Enums\RecentItemType;
use Database\Factories\RecentItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecentItem extends Model
{
    public const MAX_PER_USER_COMPANY = 25;

    /** @use HasFactory<RecentItemFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'company_id',
        'record_type',
        'record_id',
        'last_viewed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'company_id' => 'integer',
            'record_type' => RecentItemType::class,
            'record_id' => 'integer',
            'last_viewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
