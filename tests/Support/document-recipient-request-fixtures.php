<?php

use App\Enums\DocumentGenerationTemplateStatus;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\DocumentInstance;
use App\Models\DocumentInstanceVersion;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/document-workflow-fixtures.php';

if (! function_exists('makeRecipientFixturesWithSignaturePlacement')) {
    function makeRecipientFixturesWithSignaturePlacement(?array $signaturePlacement = null): array
    {
        if ($signaturePlacement === null) {
            return makeGeneratedDocumentWorkflowFixtures();
        }

        $company = makeDocumentFixtures()['company'];
        $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

        $template = DocumentGenerationTemplate::factory()->forCompany($company)->create([
            'status' => DocumentGenerationTemplateStatus::Active,
        ]);
        $templateVersion = DocumentGenerationTemplateVersion::factory()
            ->forTemplate($template)
            ->published()
            ->create([
                'signature_placement_config' => $signaturePlacement,
            ]);
        $template->update(['published_version_id' => $templateVersion->id]);

        $libraryPath = "employee-documents/{$company->id}/{$employee->id}/letter.pdf";
        $canonicalPath = "document-instances/{$company->id}/canonical.pdf";
        $pdfBytes = minimalPdfBytes();
        Storage::disk('local')->put($libraryPath, $pdfBytes);
        Storage::disk('local')->put($canonicalPath, $pdfBytes);

        $document = EmployeeDocument::query()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'type' => 'other',
            'document_type' => 'other',
            'title' => 'Generated Letter',
            'file_path' => $libraryPath,
            'original_filename' => 'letter.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($pdfBytes),
            'checksum' => hash('sha256', $pdfBytes),
            'current_version' => 1,
            'status' => 'valid',
        ]);

        $instance = DocumentInstance::query()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'employee_name_snapshot' => $employee->name,
            'employee_no_snapshot' => $employee->employee_no,
            'document_generation_template_id' => $template->id,
            'document_generation_template_version_id' => $templateVersion->id,
            'template_name_snapshot' => $template->name,
            'template_version_number' => $templateVersion->version,
            'title_snapshot' => 'Generated Letter',
            'status' => 'generated',
            'employee_document_id' => $document->id,
            'generated_at' => now(),
        ]);

        $version = DocumentInstanceVersion::query()->create([
            'company_id' => $company->id,
            'document_instance_id' => $instance->id,
            'version' => 1,
            'file_path' => $canonicalPath,
            'original_filename' => 'canonical.pdf',
            'size_bytes' => strlen($pdfBytes),
            'checksum' => hash('sha256', $pdfBytes),
        ]);

        $instance->update(['current_version_id' => $version->id]);

        return compact('company', 'employee', 'document', 'instance', 'version', 'template');
    }
}

if (! function_exists('defaultSignaturePlacementConfig')) {
    function defaultSignaturePlacementConfig(): array
    {
        return [
            'schema_version' => 1,
            'placements' => [[
                'id' => 'subject_signature',
                'type' => 'signature',
                'role' => 'subject',
                'page' => 1,
                'x' => 0.1,
                'y' => 0.75,
                'width' => 0.25,
                'height' => 0.08,
                'required' => true,
            ]],
        ];
    }
}

if (! function_exists('validSignatureDataUri')) {
    function validSignatureDataUri(): string
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        return 'data:image/png;base64,'.base64_encode($png ?: '');
    }
}

if (! function_exists('advanceDocumentInstanceCurrentVersion')) {
    function advanceDocumentInstanceCurrentVersion(DocumentInstance $instance, string $filePath): DocumentInstanceVersion
    {
        $pdfBytes = minimalPdfBytes();

        Storage::disk('local')->put($filePath, $pdfBytes);

        $nextVersion = DocumentInstanceVersion::query()->create([
            'company_id' => $instance->company_id,
            'document_instance_id' => $instance->id,
            'version' => (int) $instance->versions()->max('version') + 1,
            'file_path' => $filePath,
            'original_filename' => 'updated.pdf',
            'size_bytes' => strlen($pdfBytes),
            'checksum' => hash('sha256', $pdfBytes),
        ]);

        $instance->update(['current_version_id' => $nextVersion->id]);

        return $nextVersion;
    }
}
