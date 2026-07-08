<?php

namespace App\Support\BulkDocuments;

use App\Models\DocumentType;
use App\Models\EmailTemplate;
use App\Services\BulkDocuments\RendersEmployeeDocumentPdf;
use App\Services\SalaryCertificate\SalaryCertificatePdfRenderer;
use App\Services\SalaryDeclaration\SalaryDeclarationPdfRenderer;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class BulkDocumentTypeRegistry
{
    /**
     * @return list<array{key: string, label: string, document_type_title: string, email_template_slug: string, renderer: class-string<RendersEmployeeDocumentPdf>}>
     */
    public static function definitions(): array
    {
        return [
            [
                'key' => 'salary_declaration',
                'label' => 'Salary Declaration',
                'document_type_title' => 'Salary Declaration',
                'email_template_slug' => 'bulk_salary_declaration',
                'renderer' => SalaryDeclarationPdfRenderer::class,
            ],
            [
                'key' => 'salary_certificate',
                'label' => 'Salary Certificate',
                'document_type_title' => 'Salary Certificate',
                'email_template_slug' => 'bulk_salary_certificate',
                'renderer' => SalaryCertificatePdfRenderer::class,
            ],
        ];
    }

    /**
     * @return array{key: string, label: string, document_type_title: string, email_template_slug: string, renderer: class-string<RendersEmployeeDocumentPdf>}
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

    public static function resolveEmailTemplate(string $key): ?EmailTemplate
    {
        $definition = self::find($key);

        $template = EmailTemplate::query()
            ->enabled()
            ->where('slug', $definition['email_template_slug'])
            ->first();

        if ($template !== null) {
            return $template;
        }

        return EmailTemplate::query()
            ->enabled()
            ->whereIn('category', ['document', 'payroll'])
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->first();
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
