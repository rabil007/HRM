<?php

namespace App\Support\BulkDocuments;

use App\Models\DocumentType;
use App\Services\BulkDocuments\RendersEmployeeDocumentPdf;
use App\Services\SalaryCertificate\SalaryCertificatePdfRenderer;
use App\Services\SalaryDeclaration\SalaryDeclarationPdfRenderer;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class BulkDocumentTypeRegistry
{
    /**
     * @return list<array{key: string, label: string, document_type_title: string, renderer: class-string<RendersEmployeeDocumentPdf>}>
     */
    public static function definitions(): array
    {
        return [
            [
                'key' => 'salary_declaration',
                'label' => 'Salary Declaration',
                'document_type_title' => 'Salary Declaration',
                'renderer' => SalaryDeclarationPdfRenderer::class,
            ],
            [
                'key' => 'salary_certificate',
                'label' => 'Salary Certificate',
                'document_type_title' => 'Salary Certificate',
                'renderer' => SalaryCertificatePdfRenderer::class,
            ],
        ];
    }

    /**
     * @return array{key: string, label: string, document_type_title: string, renderer: class-string<RendersEmployeeDocumentPdf>}
     */
    public static function find(string $key): array
    {
        foreach (self::definitions() as $definition) {
            if ($definition['key'] === $key) {
                return $definition;
            }
        }

        throw new InvalidArgumentException("Unknown bulk document type [{$key}].");
    }

    public static function resolveDocumentType(string $key): DocumentType
    {
        $definition = self::find($key);

        return DocumentType::query()->firstOrCreate(
            ['title' => $definition['document_type_title']],
            ['is_active' => true],
        );
    }

    public static function resolveRenderer(string $key): RendersEmployeeDocumentPdf
    {
        $definition = self::find($key);

        return app($definition['renderer']);
    }

    /**
     * @return Collection<int, array{value: string, label: string}>
     */
    public static function options(): Collection
    {
        return collect(self::definitions())->map(fn (array $definition): array => [
            'value' => $definition['key'],
            'label' => $definition['label'],
        ]);
    }
}
