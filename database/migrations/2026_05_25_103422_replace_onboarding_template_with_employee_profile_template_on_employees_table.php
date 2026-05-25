<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['onboarding_template_id']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('onboarding_template_id');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('employee_profile_template_id')
                ->nullable()
                ->after('company_id')
                ->constrained('employee_profile_templates')
                ->nullOnDelete();
        });

        Schema::dropIfExists('onboarding_templates');
    }

    public function down(): void
    {
        Schema::create('onboarding_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->json('tasks');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['employee_profile_template_id']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('employee_profile_template_id');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('onboarding_template_id')
                ->nullable()
                ->after('company_id')
                ->constrained('onboarding_templates')
                ->nullOnDelete();
        });
    }
};
