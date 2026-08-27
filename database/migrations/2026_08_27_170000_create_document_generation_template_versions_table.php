<?php

use App\Enums\DocumentGenerationTemplateFormat;
use App\Enums\DocumentGenerationTemplateStatus;
use App\Enums\DocumentGenerationTemplateVersionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add template_format to document_generation_templates
        Schema::table('document_generation_templates', function (Blueprint $table) {
            $table->string('template_format', 20)
                ->default(DocumentGenerationTemplateFormat::Content->value)
                ->after('document_type_id');

            $table->index(['company_id', 'template_format'], 'doc_gen_tmpl_comp_format_idx');
        });

        // 2. Create document_generation_template_versions table with explicit short constraint names (<64 chars)
        Schema::create('document_generation_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies', indexName: 'doc_gen_tmpl_ver_comp_fk')
                ->cascadeOnDelete();
            $table->foreignId('document_generation_template_id')
                ->constrained('document_generation_templates', indexName: 'doc_gen_tmpl_ver_tmpl_fk')
                ->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 20)->default(DocumentGenerationTemplateVersionStatus::Draft->value);
            $table->mediumText('content')->nullable();
            $table->string('source_pdf_path')->nullable();
            $table->string('source_pdf_original_name')->nullable();
            $table->unsignedBigInteger('source_pdf_size_bytes')->nullable();
            $table->unsignedSmallInteger('source_pdf_page_count')->nullable();
            $table->json('placement_config')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users', indexName: 'doc_gen_tmpl_ver_creator_fk')
                ->nullOnDelete();
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users', indexName: 'doc_gen_tmpl_ver_updater_fk')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(['document_generation_template_id', 'version'], 'doc_gen_tmpl_ver_unique');
            $table->index(['company_id', 'status'], 'doc_gen_tmpl_ver_comp_stat_idx');
            $table->index(['document_generation_template_id', 'status'], 'doc_gen_tmpl_ver_tmpl_stat_idx');
        });

        // 3. Add published_version_id foreign key on document_generation_templates
        Schema::table('document_generation_templates', function (Blueprint $table) {
            $table->foreignId('published_version_id')
                ->nullable()
                ->after('status')
                ->constrained('document_generation_template_versions', indexName: 'doc_gen_tmpl_pub_ver_fk')
                ->nullOnDelete();
        });

        // 4. Backfill existing Phase 3A templates into document_generation_template_versions
        $templates = DB::table('document_generation_templates')->get();

        foreach ($templates as $template) {
            $parentStatus = $template->status;

            // Inactive and Active templates receive a v1 Published snapshot.
            // Inactive parents remain status = 'inactive' with published_version_id set.
            // Draft parents receive v1 Draft without published_version_id.
            $isPublished = in_array($parentStatus, [
                DocumentGenerationTemplateStatus::Active->value,
                DocumentGenerationTemplateStatus::Inactive->value,
            ], true);

            $versionStatus = $isPublished
                ? DocumentGenerationTemplateVersionStatus::Published->value
                : DocumentGenerationTemplateVersionStatus::Draft->value;

            $publishedAt = $isPublished
                ? ($template->updated_at ?? $template->created_at ?? now())
                : null;

            $versionId = DB::table('document_generation_template_versions')->insertGetId([
                'company_id' => $template->company_id,
                'document_generation_template_id' => $template->id,
                'version' => 1,
                'status' => $versionStatus,
                'content' => $template->content,
                'source_pdf_path' => null,
                'source_pdf_original_name' => null,
                'source_pdf_size_bytes' => null,
                'source_pdf_page_count' => null,
                'placement_config' => null,
                'published_at' => $publishedAt,
                'created_by' => $template->created_by,
                'updated_by' => $template->updated_by,
                'created_at' => $template->created_at ?? now(),
                'updated_at' => $template->updated_at ?? now(),
            ]);

            if ($isPublished) {
                DB::table('document_generation_templates')
                    ->where('id', $template->id)
                    ->update(['published_version_id' => $versionId]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_generation_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('published_version_id');
            $table->dropIndex('doc_gen_tmpl_comp_format_idx');
            $table->dropColumn('template_format');
        });

        Schema::dropIfExists('document_generation_template_versions');
    }
};
