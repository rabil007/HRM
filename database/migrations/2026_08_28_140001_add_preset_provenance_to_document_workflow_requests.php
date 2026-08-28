<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('document_workflow_requests', function (Blueprint $table) {
            $table->foreignId('document_workflow_preset_id')
                ->nullable()
                ->after('document_instance_version_id')
                ->constrained('document_workflow_presets', indexName: 'doc_wf_req_preset_fk')
                ->nullOnDelete();
            $table->string('preset_name_snapshot', 150)->nullable()->after('document_workflow_preset_id');
            $table->json('routing_definition_snapshot')->nullable()->after('preset_name_snapshot');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_workflow_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_workflow_preset_id');
            $table->dropColumn(['preset_name_snapshot', 'routing_definition_snapshot']);
        });
    }
};
