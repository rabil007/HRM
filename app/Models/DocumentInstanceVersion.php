<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentInstanceVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'document_instance_id',
        'version',
        'stage',
        'file_path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'checksum',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'size_bytes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (DocumentInstanceVersion $version): void {
            $dirty = array_keys($version->getDirty());
            $protectedAttributes = [
                'file_path',
                'checksum',
                'size_bytes',
                'version',
                'stage',
                'company_id',
                'document_instance_id',
                'original_filename',
                'mime_type',
            ];

            foreach ($protectedAttributes as $attr) {
                if (in_array($attr, $dirty, true)) {
                    throw new DomainException("Cannot modify immutable attribute '{$attr}' on document instance version.");
                }
            }
        });

        static::deleting(function (DocumentInstanceVersion $version): void {
            throw new DomainException('Cannot delete an immutable document instance version.');
        });
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(DocumentInstance::class, 'document_instance_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
