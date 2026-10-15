<?php

namespace App\Support\EmployeeDocuments;

use App\Models\DocumentType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

class TrainingCertificateDocumentTypeResolver
{
    public const TITLE = 'Training Certificate';

    public function findExisting(): ?DocumentType
    {
        $existing = DocumentType::withTrashed()
            ->where('title', self::TITLE)
            ->orderBy('id')
            ->first();

        return $existing instanceof DocumentType ? $existing : null;
    }

    public function resolve(): DocumentType
    {
        return Cache::lock('document-type:training-certificate', 10)->block(5, function (): DocumentType {
            $existing = DocumentType::withTrashed()
                ->where('title', self::TITLE)
                ->orderBy('id')
                ->first();

            if ($existing instanceof DocumentType) {
                if ($existing->trashed()) {
                    $existing->restore();
                }

                if (! $existing->is_active) {
                    $existing->update(['is_active' => true]);
                }

                return $existing->refresh();
            }

            try {
                return DocumentType::query()->create([
                    'title' => self::TITLE,
                    'is_active' => true,
                ]);
            } catch (QueryException) {
                return DocumentType::query()
                    ->where('title', self::TITLE)
                    ->firstOrFail();
            }
        });
    }
}
