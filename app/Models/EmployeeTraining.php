<?php

namespace App\Models;

use App\Models\Concerns\LogsActivityWithCompany;
use Database\Factories\EmployeeTrainingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;

class EmployeeTraining extends Model
{
    /** @use HasFactory<EmployeeTrainingFactory> */
    use HasFactory;

    use LogsActivityWithCompany;
    use SoftDeletes;

    protected $guarded = [];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'company_id',
                'employee_id',
                'course_id',
                'sort_order',
                'issue_date',
                'expiry_date',
                'institute_center',
                'country_id',
                'certificate_path',
                'certificate_original_filename',
                'certificate_mime_type',
                'certificate_size_bytes',
                'current_version',
                'replaced_at',
            ])
            ->logOnlyDirty();
    }

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'expiry_date' => 'date',
            'sort_order' => 'integer',
            'replaced_at' => 'datetime',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(EmployeeTrainingVersion::class)->latest('version');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toShowArray(): array
    {
        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'course_name' => $this->relationLoaded('course') ? $this->course?->name : null,
            'issue_date' => $this->issue_date?->toDateString(),
            'expiry_date' => $this->expiry_date?->toDateString(),
            'institute_center' => $this->institute_center,
            'country_id' => $this->country_id,
            'country_name' => $this->relationLoaded('country') ? $this->country?->name : null,
            'certificate_url' => $this->certificate_url,
            'certificate_original_filename' => $this->certificate_original_filename,
            'certificate_mime_type' => $this->resolvedCertificateMimeType(),
            'certificate_size_bytes' => $this->certificate_size_bytes !== null
                ? (int) $this->certificate_size_bytes
                : null,
            'current_version' => (int) ($this->current_version ?? 1),
            'replaced_at' => $this->replaced_at?->toDateTimeString(),
            'can_preview' => $this->can_preview,
            'created_at' => $this->created_at?->toDateTimeString(),
            'versions' => $this->versions->map(fn (EmployeeTrainingVersion $version) => [
                'id' => $version->id,
                'version' => $version->version,
                'file_url' => $version->file_url,
                'original_filename' => $version->original_filename,
                'mime_type' => $version->mime_type,
                'size_bytes' => $version->size_bytes,
                'replaced_by' => $version->relationLoaded('replacer') ? $version->replacer?->name : null,
                'created_at' => $version->created_at?->toDateTimeString(),
            ])->values()->all(),
        ];
    }

    public function getCertificateUrlAttribute(): ?string
    {
        if ($this->certificate_path === null || $this->certificate_path === '') {
            return null;
        }

        if (str_starts_with($this->certificate_path, 'http')) {
            return $this->certificate_path;
        }

        return asset('storage/'.ltrim($this->certificate_path, '/'));
    }

    public function getCanPreviewAttribute(): bool
    {
        $mimeType = $this->resolvedCertificateMimeType();

        if ($mimeType === null) {
            return false;
        }

        return str_starts_with($mimeType, 'image/')
            || $mimeType === 'application/pdf';
    }

    public function resolvedCertificateMimeType(): ?string
    {
        $mimeType = $this->certificate_mime_type;

        if (is_string($mimeType) && $mimeType !== '') {
            return $mimeType;
        }

        foreach ([
            $this->certificate_original_filename,
            $this->certificate_path,
        ] as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            $extension = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));

            $resolved = match ($extension) {
                'pdf' => 'application/pdf',
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                default => null,
            };

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }
}
