<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive provenance for archived employee document versions.
 *
 * uploaded_by / uploaded_at describe who originally supplied that archived version.
 * replaced_by remains who later superseded it.
 *
 * Also reconstructs EmployeeDocument.uploaded_by for the CURRENT version from the
 * replacement chain where legacy data still allows it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_document_versions', function (Blueprint $table) {
            $table->unsignedBigInteger('uploaded_by')->nullable()->after('checksum');
            $table->timestamp('uploaded_at')->nullable()->after('uploaded_by');
        });

        $this->backfillProvenance();
    }

    public function down(): void
    {
        $this->restoreDocumentUploaderFromV1();

        Schema::table('employee_document_versions', function (Blueprint $table) {
            $table->dropColumn(['uploaded_by', 'uploaded_at']);
        });
    }

    private function backfillProvenance(): void
    {
        DB::table('employee_documents')
            ->orderBy('id')
            ->chunkById(100, function ($documents): void {
                foreach ($documents as $document) {
                    $versions = DB::table('employee_document_versions')
                        ->where('employee_document_id', $document->id)
                        ->orderBy('version')
                        ->orderBy('id')
                        ->get();

                    if ($versions->isEmpty()) {
                        continue;
                    }

                    $previousUploader = $document->uploaded_by;
                    $previousUploadedAt = $document->created_at;

                    foreach ($versions as $version) {
                        DB::table('employee_document_versions')
                            ->where('id', $version->id)
                            ->update([
                                'uploaded_by' => $previousUploader,
                                'uploaded_at' => $previousUploadedAt,
                            ]);

                        $previousUploader = $version->replaced_by;
                        $previousUploadedAt = $version->created_at;
                    }

                    if ($previousUploader !== null) {
                        DB::table('employee_documents')
                            ->where('id', $document->id)
                            ->update(['uploaded_by' => $previousUploader]);
                    }
                }
            });
    }

    private function restoreDocumentUploaderFromV1(): void
    {
        DB::table('employee_documents')
            ->orderBy('id')
            ->chunkById(100, function ($documents): void {
                foreach ($documents as $document) {
                    $v1 = DB::table('employee_document_versions')
                        ->where('employee_document_id', $document->id)
                        ->orderBy('version')
                        ->orderBy('id')
                        ->first();

                    if ($v1 === null || $v1->uploaded_by === null) {
                        continue;
                    }

                    DB::table('employee_documents')
                        ->where('id', $document->id)
                        ->update(['uploaded_by' => $v1->uploaded_by]);
                }
            });
    }
};
