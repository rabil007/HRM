<?php

namespace App\Models;

use App\Models\Concerns\LogsActivityWithCompany;
use Database\Factories\DocumentRequirementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\Support\LogOptions;

class DocumentRequirement extends Model
{
    /** @use HasFactory<DocumentRequirementFactory> */
    use HasFactory;

    use LogsActivityWithCompany;

    protected $guarded = [];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'required_for_all' => false,
        'require_issue_date' => false,
        'require_expiry_date' => false,
        'require_document_number' => false,
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'required_for_all' => 'boolean',
            'require_issue_date' => 'boolean',
            'require_expiry_date' => 'boolean',
            'require_document_number' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->dontLogEmptyChanges();
    }

    /**
     * @param  Builder<DocumentRequirement>  $query
     * @return Builder<DocumentRequirement>
     */
    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where($query->qualifyColumn('company_id'), $companyId);
    }

    /**
     * @param  Builder<DocumentRequirement>  $query
     * @return Builder<DocumentRequirement>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), true);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'document_requirement_department')
            ->orderBy('name');
    }

    public function positions(): BelongsToMany
    {
        return $this->belongsToMany(Position::class, 'document_requirement_position')
            ->orderBy('title');
    }

    public function ranks(): BelongsToMany
    {
        return $this->belongsToMany(Rank::class, 'document_requirement_rank')
            ->orderBy('name');
    }
}
