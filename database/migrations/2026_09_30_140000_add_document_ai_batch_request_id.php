<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_ai_batches', function (Blueprint $table) {
            $table->uuid('batch_request_id')->nullable()->after('employee_id');
            $table->unique(
                ['company_id', 'user_id', 'employee_id', 'batch_request_id'],
                'document_ai_batches_request_uidx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('document_ai_batches', function (Blueprint $table) {
            $table->dropUnique('document_ai_batches_request_uidx');
            $table->dropColumn('batch_request_id');
        });
    }
};
