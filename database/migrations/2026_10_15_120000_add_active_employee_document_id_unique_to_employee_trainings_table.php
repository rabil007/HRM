<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nullable generated live document-link key.
     *
     * Conceptually UNIQUE(CASE WHEN deleted_at IS NULL THEN employee_document_id ELSE NULL END).
     * Soft-deleted rows store NULL, so historical links do not occupy the live unique slot.
     * Multiple NULLs are allowed (trainings without a linked document).
     */
    public const COLUMN = 'active_employee_document_id';

    public const INDEX = 'uq_employee_trainings_active_employee_document_id';

    public const EXPRESSION = 'CASE WHEN deleted_at IS NULL THEN employee_document_id ELSE NULL END';

    public function up(): void
    {
        $this->abortIfDuplicateActiveDocumentLinksExist();

        Schema::table('employee_trainings', function (Blueprint $table): void {
            $table->unsignedBigInteger(self::COLUMN)
                ->nullable()
                ->virtualAs(self::EXPRESSION);
        });

        Schema::table('employee_trainings', function (Blueprint $table): void {
            $table->unique(self::COLUMN, self::INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('employee_trainings', function (Blueprint $table): void {
            $table->dropUnique(self::INDEX);
        });

        Schema::table('employee_trainings', function (Blueprint $table): void {
            $table->dropColumn(self::COLUMN);
        });
    }

    private function abortIfDuplicateActiveDocumentLinksExist(): void
    {
        $duplicateGroupCount = DB::table('employee_trainings')
            ->whereNull('deleted_at')
            ->whereNotNull('employee_document_id')
            ->select('employee_document_id')
            ->groupBy('employee_document_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        if ($duplicateGroupCount === 0) {
            return;
        }

        throw new RuntimeException(
            "Cannot add unique active training document links: {$duplicateGroupCount} employee_document_id group(s) are linked to more than one non-deleted training. Resolve them first with: php artisan training:audit-certificate-document-ownership"
        );
    }
};
