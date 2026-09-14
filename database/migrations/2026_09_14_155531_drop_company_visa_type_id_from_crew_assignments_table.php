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
        Schema::table('crew_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_visa_type_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('crew_assignments', function (Blueprint $table) {
            $table->foreignId('company_visa_type_id')->nullable()->constrained('company_visa_types')->nullOnDelete();
        });
    }
};
