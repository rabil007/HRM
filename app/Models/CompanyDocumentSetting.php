<?php

namespace App\Models;

use App\Models\Concerns\LogsActivityWithCompany;
use Database\Factories\CompanyDocumentSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Support\LogOptions;

class CompanyDocumentSetting extends Model
{
    /** @use HasFactory<CompanyDocumentSettingFactory> */
    use HasFactory;

    use LogsActivityWithCompany;

    protected $fillable = [
        'company_id',
        'document_type',
        'signatory_name',
        'signatory_title',
        'signature_path',
        'stamp_path',
        'footer_text',
        'effective_from',
        'effective_to',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'updated_by' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'company_id',
                'document_type',
                'signatory_name',
                'signatory_title',
                'signature_path',
                'stamp_path',
                'footer_text',
                'effective_from',
                'effective_to',
                'updated_by',
            ])
            ->logOnlyDirty();
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function signatureUrl(): ?string
    {
        if (! filled($this->signature_path)) {
            return null;
        }

        return Storage::disk('public')->url((string) $this->signature_path);
    }

    public function stampUrl(): ?string
    {
        if (! filled($this->stamp_path)) {
            return null;
        }

        return Storage::disk('public')->url((string) $this->stamp_path);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSettingsArray(): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'document_type' => $this->document_type,
            'signatory_name' => $this->signatory_name,
            'signatory_title' => $this->signatory_title,
            'signature_url' => $this->signatureUrl(),
            'stamp_url' => $this->stampUrl(),
            'has_signature' => filled($this->signature_path),
            'has_stamp' => filled($this->stamp_path),
            'footer_text' => $this->footer_text,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
        ];
    }
}
