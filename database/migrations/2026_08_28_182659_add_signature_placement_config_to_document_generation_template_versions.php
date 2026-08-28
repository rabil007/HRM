<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_generation_template_versions', function (Blueprint $table) {
            $table->json('signature_placement_config')->nullable()->after('placement_config');
        });
    }

    public function down(): void
    {
        Schema::table('document_generation_template_versions', function (Blueprint $table) {
            $table->dropColumn('signature_placement_config');
        });
    }
};
