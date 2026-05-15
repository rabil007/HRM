<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class OnboardingTemplate extends Model
{
    protected $guarded = [];

    protected $casts = [
        'tasks' => 'array',
        'is_default' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function ranks(): BelongsToMany
    {
        return $this->belongsToMany(Rank::class, 'onboarding_template_rank');
    }

    public function scopeForRank(Builder $query, ?int $rankId): Builder
    {
        if (! $rankId) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($rankId) {
            $q->whereDoesntHave('ranks')
                ->orWhereHas('ranks', fn (Builder $r) => $r->where('ranks.id', $rankId));
        });
    }

    public function appliesToRank(?int $rankId): bool
    {
        if (! $rankId) {
            return true;
        }

        if ($this->relationLoaded('ranks')) {
            return $this->ranks->isEmpty() || $this->ranks->contains('id', $rankId);
        }

        return ! $this->ranks()->exists() || $this->ranks()->where('ranks.id', $rankId)->exists();
    }
}
