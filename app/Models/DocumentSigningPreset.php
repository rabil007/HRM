<?php

namespace App\Models;

use App\Enums\DocumentSigningPresetStatus;
use Database\Factories\DocumentSigningPresetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentSigningPreset extends Model
{
    /** @use HasFactory<DocumentSigningPresetFactory> */
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'status',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DocumentSigningPresetStatus::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(DocumentSigningPresetStep::class)
            ->orderBy('sequence');
    }

    public function signingFlows(): HasMany
    {
        return $this->hasMany(DocumentSigningFlow::class);
    }

    /**
     * @param  Builder<DocumentSigningPreset>  $query
     * @return Builder<DocumentSigningPreset>
     */
    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * @param  Builder<DocumentSigningPreset>  $query
     * @return Builder<DocumentSigningPreset>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', DocumentSigningPresetStatus::Active);
    }
}
