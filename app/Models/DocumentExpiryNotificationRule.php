<?php

namespace App\Models;

use App\Models\Concerns\LogsActivityWithCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;

class DocumentExpiryNotificationRule extends Model
{
    use LogsActivityWithCompany;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'enabled',
        'all_document_types',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'all_document_types' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'company_id',
                'name',
                'enabled',
                'all_document_types',
            ])
            ->logOnlyDirty();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function documentTypes(): BelongsToMany
    {
        return $this->belongsToMany(
            DocumentType::class,
            'document_expiry_notification_rule_document_types',
            'rule_id',
            'document_type_id',
        )->withTimestamps();
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(DocumentExpiryNotificationRuleRecipient::class, 'rule_id');
    }

    public function toRecipients(): HasMany
    {
        return $this->hasMany(DocumentExpiryNotificationRuleRecipient::class, 'rule_id')
            ->where('delivery_type', 'to');
    }

    public function ccRecipients(): HasMany
    {
        return $this->hasMany(DocumentExpiryNotificationRuleRecipient::class, 'rule_id')
            ->where('delivery_type', 'cc');
    }

    public function expiryAlerts(): HasMany
    {
        return $this->hasMany(EmployeeDocumentExpiryAlert::class, 'notification_rule_id');
    }

    public function coversDocumentType(?int $documentTypeId): bool
    {
        if ($this->all_document_types) {
            return true;
        }

        if ($documentTypeId === null || $documentTypeId <= 0) {
            return false;
        }

        if ($this->relationLoaded('documentTypes')) {
            return $this->documentTypes->contains(
                fn (DocumentType $type): bool => (int) $type->id === $documentTypeId,
            );
        }

        return $this->documentTypes()
            ->where('document_types.id', $documentTypeId)
            ->exists();
    }
}
