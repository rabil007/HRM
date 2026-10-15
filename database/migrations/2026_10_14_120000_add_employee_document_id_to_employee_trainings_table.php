<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_trainings', function (Blueprint $table) {
            $table->foreignId('employee_document_id')
                ->nullable()
                ->after('certificate_checksum')
                ->constrained('employee_documents')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employee_trainings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_document_id');
        });
    }
};
