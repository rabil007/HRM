<?php

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
        Schema::table('employee_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_documents', 'document_type_id')) {
                $table->foreignId('document_type_id')
                    ->nullable()
                    ->after('document_type')
                    ->constrained('document_types')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('employee_documents', 'original_filename')) {
                $table->string('original_filename', 255)->nullable()->after('file_path');
            }

            if (! Schema::hasColumn('employee_documents', 'mime_type')) {
                $table->string('mime_type', 120)->nullable()->after('original_filename');
            }

            if (! Schema::hasColumn('employee_documents', 'size_bytes')) {
                $table->unsignedBigInteger('size_bytes')->nullable()->after('mime_type');
            }

            if (! Schema::hasColumn('employee_documents', 'checksum')) {
                $table->string('checksum', 64)->nullable()->after('size_bytes');
            }

            if (! Schema::hasColumn('employee_documents', 'current_version')) {
                $table->unsignedInteger('current_version')->default(1)->after('checksum');
            }

            if (! Schema::hasColumn('employee_documents', 'replaced_at')) {
                $table->timestamp('replaced_at')->nullable()->after('current_version');
            }
        });

        $documentTypes = DB::table('document_types')
            ->get(['id', 'title', 'slug'])
            ->flatMap(fn ($type) => [
                (string) $type->slug => $type->id,
                (string) $type->title => $type->id,
            ]);

        DB::table('employee_documents')
            ->whereNull('document_type_id')
            ->whereNotNull('document_type')
            ->orderBy('id')
            ->get(['id', 'document_type'])
            ->each(function ($document) use ($documentTypes) {
                $documentTypeId = $documentTypes->get((string) $document->document_type);

                if ($documentTypeId) {
                    DB::table('employee_documents')
                        ->where('id', $document->id)
                        ->update(['document_type_id' => $documentTypeId]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee_documents', function (Blueprint $table) {
            if (Schema::hasColumn('employee_documents', 'document_type_id')) {
                $table->dropConstrainedForeignId('document_type_id');
            }

            foreach ([
                'replaced_at',
                'current_version',
                'checksum',
                'size_bytes',
                'mime_type',
                'original_filename',
            ] as $column) {
                if (Schema::hasColumn('employee_documents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
