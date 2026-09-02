<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_generation_template_versions', function (Blueprint $table) {
            $table->string('document_workflow_mode', 16)
                ->nullable()
                ->after('signature_placement_config');

            $table->string('document_signing_mode', 16)
                ->nullable()
                ->after('document_workflow_preset_id');
        });
    }

    public function down(): void
    {
        Schema::table('document_generation_template_versions', function (Blueprint $table) {
            $table->dropColumn(['document_workflow_mode', 'document_signing_mode']);
        });
    }
};
