<?php

namespace App\Models;

use App\Models\Concerns\LogsActivityWithCompany;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Support\LogOptions;

class DocumentInstance extends Model
{
    use HasFactory;
    use LogsActivityWithCompany;

    protected $fillable = [
        'company_id',
        'employee_id',
        'employee_name_snapshot',
        'employee_no_snapshot',
        'document_generation_template_id',
        'document_generation_template_version_id',
        'document_type_id',
        'document_generation_run_id',
        'employee_document_id',
        'template_name_snapshot',
        'template_version_number',
        'title_snapshot',
        'status',
        'current_version_id',
        'generated_by',
        'generated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'template_version_number' => 'integer',
            'generated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (DocumentInstance $instance): void {
            $dirty = array_keys($instance->getDirty());
            $protectedAttributes = [
                'company_id',
                'employee_id',
                'employee_name_snapshot',
                'employee_no_snapshot',
                'document_generation_template_id',
                'document_generation_template_version_id',
                'document_type_id',
                'document_generation_run_id',
                'template_name_snapshot',
                'template_version_number',
                'title_snapshot',
                'generated_by',
                'generated_at',
            ];

            foreach ($protectedAttributes as $attr) {
                if (in_array($attr, $dirty, true)) {
                    throw new DomainException("Cannot modify immutable attribute '{$attr}' on document instance.");
                }
            }
        });

        static::deleting(function (DocumentInstance $instance): void {
            throw new DomainException('Cannot delete an immutable document instance.');
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'company_id',
                'employee_id',
                'employee_name_snapshot',
                'document_generation_template_id',
                'document_generation_template_version_id',
                'template_name_snapshot',
                'template_version_number',
                'title_snapshot',
                'status',
                'generated_at',
            ])
            ->logOnlyDirty();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentGenerationTemplate::class, 'document_generation_template_id');
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentGenerationTemplateVersion::class, 'document_generation_template_version_id');
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(DocumentGenerationRun::class, 'document_generation_run_id');
    }

    public function employeeDocument(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentInstanceVersion::class, 'current_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentInstanceVersion::class, 'document_instance_id')
            ->orderBy('version');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function workflowRequests(): HasMany
    {
        return $this->hasMany(DocumentWorkflowRequest::class, 'document_instance_id');
    }

    public function recipientRequests(): HasMany
    {
        return $this->hasMany(DocumentRecipientRequest::class, 'document_instance_id');
    }

    public function lifecycleAutomation(): HasOne
    {
        return $this->hasOne(DocumentLifecycleAutomation::class, 'document_instance_id');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForTemplateVersion(Builder $query, int $versionId): Builder
    {
        return $query->where('document_generation_template_version_id', $versionId);
    }

    public function scopeWithLibraryDocument(Builder $query): Builder
    {
        return $query
            ->whereNotNull('employee_document_id')
            ->whereHas('employeeDocument');
    }
}
