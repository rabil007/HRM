<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Normalize invalid actor id 0 to NULL on employee document provenance columns.
 *
 * Nullable uploaded_by / replaced_by must never store 0 (there is no user id 0).
 * Legacy callers and PR #146 backfill chains could propagate replaced_by = 0 into
 * EmployeeDocument.uploaded_by; this cleanup converts only those zeros to NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('employee_documents')
            ->where('uploaded_by', 0)
            ->update(['uploaded_by' => null]);

        DB::table('employee_document_versions')
            ->where('uploaded_by', 0)
            ->update(['uploaded_by' => null]);

        DB::table('employee_document_versions')
            ->where('replaced_by', 0)
            ->update(['replaced_by' => null]);
    }

    public function down(): void
    {
        // Irreversible data normalization: restoring 0 would reintroduce invalid
        // provenance. Absence of a known actor must remain NULL.
    }
};
