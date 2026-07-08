<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulk_document_email_batches', function (Blueprint $table) {
            if (! Schema::hasColumn('bulk_document_email_batches', 'status')) {
                $table->string('status', 32)->default('completed')->after('skipped_no_email_count');
            }

            if (! Schema::hasColumn('bulk_document_email_batches', 'started_at')) {
                $table->timestamp('started_at')->nullable()->after('status');
            }

            if (! Schema::hasColumn('bulk_document_email_batches', 'finished_at')) {
                $table->timestamp('finished_at')->nullable()->after('started_at');
            }

            if (! collect(Schema::getIndexes('bulk_document_email_batches'))->pluck('name')->contains('bdeb_company_status_idx')) {
                $table->index(['company_id', 'status'], 'bdeb_company_status_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bulk_document_email_batches', function (Blueprint $table) {
            if (collect(Schema::getIndexes('bulk_document_email_batches'))->pluck('name')->contains('bdeb_company_status_idx')) {
                $table->dropIndex('bdeb_company_status_idx');
            }

            $table->dropColumn(
                array_filter(['status', 'started_at', 'finished_at'], fn ($col) => Schema::hasColumn('bulk_document_email_batches', $col)),
            );
        });
    }
};
