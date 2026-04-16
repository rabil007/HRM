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
            if (Schema::hasColumn('employees', 'bank_id')) {
                $table->dropConstrainedForeignId('bank_id');
            }

            if (Schema::hasColumn('employees', 'iban')) {
                $table->dropColumn('iban');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'iban')) {
                $table->string('iban', 50)->nullable()->after('bank_id');
            }

            if (! Schema::hasColumn('employees', 'bank_id')) {
                $table->foreignId('bank_id')->nullable()->after('labor_card_number')->constrained('banks')->nullOnDelete();
            }
        });
    }
};
