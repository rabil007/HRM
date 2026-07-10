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
     * @return list<array{key: string, label: string, document_type_title: string, email_template_slug: string, reminder_email_template_slug: ?string, supports_esignature: bool, renderer: class-string<RendersEmployeeDocumentPdf>}>
     */
    public static function definitions(): array
    {
        return [
            [
                'key' => 'salary_declaration',
                'label' => 'Salary Declaration',
                'document_type_title' => 'Salary Declaration',
                'email_template_slug' => 'bulk_salary_declaration',
                'reminder_email_template_slug' => 'bulk_salary_declaration_sign_reminder',
                'supports_esignature' => true,
                'renderer' => SalaryDeclarationPdfRenderer::class,
            ],
            [
                'key' => 'salary_certificate',
                'label' => 'Salary Certificate',
                'document_type_title' => 'Salary Certificate',
                'email_template_slug' => 'bulk_salary_certificate',
                'reminder_email_template_slug' => null,
                'supports_esignature' => false,
                'renderer' => SalaryCertificatePdfRenderer::class,
            ],
        ];
    }

    /**
     * @return array{key: string, label: string, document_type_title: string, email_template_slug: string, reminder_email_template_slug: ?string, supports_esignature: bool, renderer: class-string<RendersEmployeeDocumentPdf>}
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

    public static function supportsEsignature(string $key): bool
    {
        return (bool) self::find($key)['supports_esignature'];
    }

    /**
     * @return array{
     *     page: int,
     *     overlay: array{left: string, top: string, width: string, height: string},
     *     stamps: list<array{type: string, x: float, y: float, w?: float, h?: float}>
     * }|null
     */
    public static function resolveSignaturePlacements(string $key): ?array
    {
        if (! self::supportsEsignature($key)) {
            return null;
        }

        return app(BulkDocumentSignaturePlacementService::class)->resolve($key);
    }

    public static function resolveEmailTemplate(string $key, string $intent = 'initial'): ?EmailTemplate
    {
        $definition = self::find($key);

        $slug = $intent === 'reminder'
            ? ($definition['reminder_email_template_slug'] ?? null)
            : $definition['email_template_slug'];

        if ($slug === null || $slug === '') {
            if ($intent === 'reminder') {
                $slug = $definition['email_template_slug'];
            }
        }

        $template = EmailTemplate::query()
            ->enabled()
            ->where('slug', $slug)
            ->first();

        if ($template !== null) {
            return $template;
        }

        if ($intent === 'reminder') {
            return self::resolveEmailTemplate($key, 'initial');
        }

        return EmailTemplate::query()
            ->enabled()
            ->whereIn('category', ['document', 'payroll'])
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->first();
    }

    /**
     * @return list<string>
     */
    public static function emailTemplateSlugs(): array
    {
        return collect(self::definitions())
            ->flatMap(fn (array $definition): array => array_values(array_filter([
                $definition['email_template_slug'],
                $definition['reminder_email_template_slug'] ?? null,
            ])))
            ->unique()
            ->values()
            ->all();
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
