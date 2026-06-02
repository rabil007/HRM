<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_trainings', function (Blueprint $table) {
            $table->foreignId('course_id')->nullable()->change();
            $table->date('issue_date')->nullable()->change();
            $table->string('institute_center', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('employee_trainings', function (Blueprint $table) {
            $table->foreignId('course_id')->nullable(false)->change();
            $table->date('issue_date')->nullable(false)->change();
            $table->string('institute_center', 255)->nullable(false)->change();
        });
    }
};
