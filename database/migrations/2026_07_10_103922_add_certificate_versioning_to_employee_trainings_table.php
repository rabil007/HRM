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
        Schema::table('employee_trainings', function (Blueprint $table) {
            $table->string('certificate_original_filename', 255)->nullable()->after('certificate_path');
            $table->string('certificate_mime_type', 120)->nullable()->after('certificate_original_filename');
            $table->unsignedBigInteger('certificate_size_bytes')->nullable()->after('certificate_mime_type');
            $table->string('certificate_checksum', 64)->nullable()->after('certificate_size_bytes');
            $table->unsignedInteger('current_version')->default(1)->after('certificate_checksum');
            $table->timestamp('replaced_at')->nullable()->after('current_version');
        });

        Schema::create('employee_training_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_training_id')->constrained('employee_trainings')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('file_path', 500);
            $table->string('original_filename', 255)->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->unsignedBigInteger('replaced_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['employee_training_id', 'version'], 'uq_employee_training_version');
            $table->index(['company_id', 'employee_id'], 'idx_training_versions_company_employee');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_training_versions');

        Schema::table('employee_trainings', function (Blueprint $table) {
            $table->dropColumn([
                'certificate_original_filename',
                'certificate_mime_type',
                'certificate_size_bytes',
                'certificate_checksum',
                'current_version',
                'replaced_at',
            ]);
        });
    }
};
