<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendance_records') && Schema::hasColumn('attendance_records', 'shift_id')) {
            Schema::table('attendance_records', function (Blueprint $table) {
                $table->dropForeign(['shift_id']);
                $table->dropColumn('shift_id');
            });
        }

        Schema::dropIfExists('employee_shifts');
        Schema::dropIfExists('shifts');
    }

    public function down(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedInteger('break_minutes')->default(60);
            $table->json('working_days');
            $table->boolean('is_night_shift')->default(false);
            $table->unsignedInteger('overtime_after')->default(480);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });

        Schema::create('employee_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('shift_id');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->timestamps();

            $table->foreign('shift_id')
                ->references('id')
                ->on('shifts');
        });

        if (Schema::hasTable('attendance_records') && ! Schema::hasColumn('attendance_records', 'shift_id')) {
            Schema::table('attendance_records', function (Blueprint $table) {
                $table->foreignId('shift_id')->nullable()->after('employee_id');

                $table->foreign('shift_id')
                    ->references('id')
                    ->on('shifts')
                    ->nullOnDelete();
            });
        }
    }
};
