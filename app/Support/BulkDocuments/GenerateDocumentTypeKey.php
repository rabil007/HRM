<?php

namespace App\Support\BulkDocuments;

use App\Models\DocumentGenerationTemplate;

final class GenerateDocumentTypeKey
{
    public static function isCustom(string $key): bool
    {
        return str_starts_with($key, 'custom_');
    }

    public static function customTemplateId(string $key): ?int
    {
        if (preg_match('/^custom_([1-9]\d*)$/', $key, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * @return list<string>
     */
    public static function systemKeys(): array
    {
        return collect(BulkDocumentTypeRegistry::definitions())->pluck('key')->all();
    }

    public static function isAllowedForCompany(int $companyId, string $key): bool
    {
        if (in_array($key, self::systemKeys(), true)) {
            return true;
        }

        return self::publishedTemplate($companyId, $key) !== null;
    }

    public static function publishedTemplate(int $companyId, string $key): ?DocumentGenerationTemplate
    {
        $templateId = self::customTemplateId($key);

        if ($templateId === null) {
            return null;
        }

        return DocumentGenerationTemplate::query()
            ->forCompany($companyId)
            ->whereKey($templateId)
            ->whereNotNull('published_version_id')
            ->with('publishedVersion')
            ->first();
    }
}
