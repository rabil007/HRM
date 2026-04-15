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
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('visa_type_id')->nullable()->after('visa_type')->constrained('visa_types')->nullOnDelete();
            $table->foreignId('religion_id')->nullable()->after('religion')->constrained('religions')->nullOnDelete();
            $table->foreignId('gender_id')->nullable()->after('gender')->constrained('genders')->nullOnDelete();
            $table->foreignId('bank_id')->nullable()->after('iban')->constrained('banks')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('visa_type_id');
            $table->dropConstrainedForeignId('religion_id');
            $table->dropConstrainedForeignId('gender_id');
            $table->dropConstrainedForeignId('bank_id');
        });
    }
};
