<?php

namespace App\Models;

use App\Support\EmployeeFiles\EmployeePrivateFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeDocumentVersion extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'uploaded_at' => 'datetime',
        'size_bytes' => 'integer',
        'version' => 'integer',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'employee_document_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function replacer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replaced_by');
    }

    public function getFileUrlAttribute(): string
    {
        if (EmployeePrivateFile::isRemoteUrl($this->file_path)) {
            return (string) $this->file_path;
        }

        return route('organization.documents.files.versions.preview', [
            'document' => $this->employee_document_id,
            'version' => $this,
        ]);
    }

    public function resolvedMimeType(): ?string
    {
        $mimeType = $this->mime_type;

        if (is_string($mimeType) && $mimeType !== '') {
            return $mimeType;
        }

        foreach ([
            $this->original_filename,
            $this->file_path,
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
