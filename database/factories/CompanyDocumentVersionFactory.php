<?php

namespace Database\Factories;

use App\Models\CompanyDocument;
use App\Models\CompanyDocumentVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyDocumentVersion>
 */
class CompanyDocumentVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_document_id' => CompanyDocument::factory(),
            'company_id' => fn (array $attributes) => CompanyDocument::query()->findOrFail($attributes['company_document_id'])->company_id,
            'version' => 1,
            'file_path' => 'company-documents/test/document-v1.pdf',
            'original_filename' => 'document-v1.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'checksum' => hash('sha256', 'document-v1'),
            'replaced_by' => User::factory(),
        ];
    }
}
