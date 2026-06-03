<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_education_qualifications', function (Blueprint $table) {
            $table->string('certificate', 200)->nullable()->change();
        });

        Schema::table('employee_work_experiences', function (Blueprint $table) {
            $table->string('company_name', 255)->nullable()->change();
            $table->string('job_title', 255)->nullable()->change();
            $table->date('date_from')->nullable()->change();
        });

        Schema::table('employee_languages', function (Blueprint $table) {
            $table->string('language_name', 255)->nullable()->change();
        });

        Schema::table('employee_vaccinations', function (Blueprint $table) {
            $table->string('vaccination_name', 255)->nullable()->change();
        });

        Schema::table('employee_sea_services', function (Blueprint $table) {
            $table->foreignId('vessel_type_id')->nullable()->change();
            $table->string('vessel_name', 255)->nullable()->change();
            $table->foreignId('rank_id')->nullable()->change();
        });

        Schema::table('employee_contracts', function (Blueprint $table) {
            $table->string('contract_type', 50)->nullable()->change();
            $table->date('start_date')->nullable()->change();
            $table->string('status', 20)->nullable()->change();
        });

        if (Schema::hasColumn('employee_documents', 'document_type_id')) {
            Schema::table('employee_documents', function (Blueprint $table) {
                $table->foreignId('document_type_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('employee_education_qualifications', function (Blueprint $table) {
            $table->string('certificate', 200)->nullable(false)->change();
        });

        Schema::table('employee_work_experiences', function (Blueprint $table) {
            $table->string('company_name', 255)->nullable(false)->change();
            $table->string('job_title', 255)->nullable(false)->change();
            $table->date('date_from')->nullable(false)->change();
        });

        Schema::table('employee_languages', function (Blueprint $table) {
            $table->string('language_name', 255)->nullable(false)->change();
        });

        Schema::table('employee_vaccinations', function (Blueprint $table) {
            $table->string('vaccination_name', 255)->nullable(false)->change();
        });

        Schema::table('employee_sea_services', function (Blueprint $table) {
            $table->foreignId('vessel_type_id')->nullable(false)->change();
            $table->string('vessel_name', 255)->nullable(false)->change();
            $table->foreignId('rank_id')->nullable(false)->change();
        });

        Schema::table('employee_contracts', function (Blueprint $table) {
            $table->string('contract_type', 50)->nullable(false)->change();
            $table->date('start_date')->nullable(false)->change();
            $table->string('status', 20)->nullable(false)->change();
        });

        if (Schema::hasColumn('employee_documents', 'document_type_id')) {
            Schema::table('employee_documents', function (Blueprint $table) {
                $table->foreignId('document_type_id')->nullable(false)->change();
            });
        }
    }
};
