<?php

namespace App\Support\Documents\RecipientRequests;

use App\Enums\DocumentRecipientRole;
use App\Models\DocumentInstance;
use App\Models\DocumentInstanceVersion;
use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use App\Support\BulkDocuments\SalaryDeclarationSignaturePlacements;
use App\Support\Documents\DocumentInstanceStorage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Fpdi;

final class ResolveDocumentSignaturePlacement
{
    /**
     * @return array{id: string, type: string, role: string, page: int, x: float, y: float, width: float, height: float, required: bool}
     */
    public function forInstanceVersion(
        DocumentInstance $instance,
        DocumentInstanceVersion $version,
        DocumentRecipientRole $role = DocumentRecipientRole::Subject,
    ): array {
        $instance->loadMissing(['templateVersion.template']);

        $templateVersion = $instance->templateVersion;

        if ($templateVersion !== null && is_array($templateVersion->signature_placement_config)) {
            $pageCount = $this->resolvePageCount($version);

            return DocumentSignaturePlacementValidator::validateSignatureForRole(
                $templateVersion->signature_placement_config,
                $pageCount,
                $role,
            );
        }

        if ($role === DocumentRecipientRole::CompanySignatory) {
            throw ValidationException::withMessages([
                'action' => 'This document does not have a trusted company signatory signature placement configured.',
            ]);
        }

        $template = $instance->template;

        if ($template !== null && $template->template_format === 'pdf_overlay') {
            throw ValidationException::withMessages([
                'action' => 'This document does not have a trusted signature placement configured.',
            ]);
        }

        $systemPlacement = $this->resolveSystemRendererPlacement($instance);

        if ($systemPlacement !== null) {
            return $systemPlacement;
        }

        throw ValidationException::withMessages([
            'action' => 'This document does not have a trusted signature placement configured.',
        ]);
    }

    /**
     * @return array{id: string, type: string, role: string, page: int, x: float, y: float, width: float, height: float, required: bool}|null
     */
    private function resolveSystemRendererPlacement(DocumentInstance $instance): ?array
    {
        $instance->loadMissing('documentType');

        $documentType = $instance->documentType;

        if ($documentType === null) {
            return null;
        }

        $slug = strtolower((string) ($documentType->slug ?? ''));

        if ($slug === '' && $documentType->title !== null) {
            $slug = strtolower(str_replace(' ', '_', trim($documentType->title)));
        }

        if ($slug !== 'salary_declaration' && ! BulkDocumentTypeRegistry::supportsEsignature('salary_declaration')) {
            return null;
        }

        if ($slug !== 'salary_declaration') {
            return null;
        }

        $legacy = SalaryDeclarationSignaturePlacements::config();
        $overlay = $legacy['overlay'] ?? null;

        if (! is_array($overlay)) {
            return null;
        }

        return [
            'id' => 'subject_signature',
            'type' => 'signature',
            'role' => 'subject',
            'page' => (int) ($legacy['page'] ?? 1),
            'x' => $this->percentToNormalized((string) ($overlay['left'] ?? '10%')),
            'y' => $this->percentToNormalized((string) ($overlay['top'] ?? '75%')),
            'width' => $this->percentToNormalized((string) ($overlay['width'] ?? '25%')),
            'height' => $this->percentToNormalized((string) ($overlay['height'] ?? '8%')),
            'required' => true,
        ];
    }

    private function percentToNormalized(string $value): float
    {
        $value = trim($value);

        if (str_ends_with($value, '%')) {
            return max(0.0, min(1.0, (float) rtrim($value, '%') / 100));
        }

        return max(0.0, min(1.0, (float) $value));
    }

    private function resolvePageCount(DocumentInstanceVersion $version): int
    {
        $path = DocumentInstanceStorage::validatedRelativePath($version->file_path, (int) $version->company_id);

        if ($path === null) {
            throw ValidationException::withMessages([
                'action' => 'The source document is unavailable.',
            ]);
        }

        $absolute = Storage::disk(DocumentInstanceStorage::DISK)->path($path);

        if (! is_readable($absolute)) {
            throw ValidationException::withMessages([
                'action' => 'The source document is unavailable.',
            ]);
        }

        try {
            $pdf = new Fpdi;

            return $pdf->setSourceFile($absolute);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'action' => 'The source document is unavailable.',
            ]);
        }
    }
}
