<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Rank extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function onboardingTemplates(): BelongsToMany
    {
        return $this->belongsToMany(OnboardingTemplate::class, 'onboarding_template_rank');
    }
}
