<?php

namespace App\Models;

use App\Enums\DocumentGenerationTemplateStatus;
use App\Models\Concerns\LogsActivityWithCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Support\LogOptions;

class DocumentGenerationTemplate extends Model
{
    use HasFactory;
    use LogsActivityWithCompany;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DocumentGenerationTemplateStatus::class,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'name',
                'description',
                'document_type_id',
                'status',
            ])
            ->logOnlyDirty();
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

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', DocumentGenerationTemplateStatus::Active);
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     description: ?string,
     *     document_type_id: ?int,
     *     document_type_title: ?string,
     *     content: string,
     *     status: string,
     *     status_label: string,
     *     created_by: ?int,
     *     created_by_name: ?string,
     *     updated_by: ?int,
     *     updated_by_name: ?string,
     *     created_at: ?string,
     *     updated_at: ?string
     * }
     */
    public function toBrowseArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'document_type_id' => $this->document_type_id,
            'document_type_title' => $this->documentType?->title,
            'content' => $this->content,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'created_by' => $this->created_by,
            'created_by_name' => $this->creator?->name,
            'updated_by' => $this->updated_by,
            'updated_by_name' => $this->updater?->name,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
