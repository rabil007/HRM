<?php

namespace App\Models;

use Database\Factories\CompanyDocumentVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyDocumentVersion extends Model
{
    /** @use HasFactory<CompanyDocumentVersionFactory> */
    use HasFactory;

    protected $fillable = [
        'company_document_id',
        'company_id',
        'version',
        'file_path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'checksum',
        'replaced_by',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'size_bytes' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(CompanyDocument::class, 'company_document_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function replacer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replaced_by');
    }
}
