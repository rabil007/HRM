<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->dropUnique('uq_document_types_slug');
            $table->dropColumn('slug');
        });
    }

    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->string('slug', 200)->nullable()->after('title');
            $table->unique('slug', 'uq_document_types_slug');
        });
    }
};
