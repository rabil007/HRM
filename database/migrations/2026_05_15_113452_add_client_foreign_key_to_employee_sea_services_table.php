<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_sea_services', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('rank_id')->constrained('clients')->restrictOnDelete();
        });

        Schema::table('employee_sea_services', function (Blueprint $table) {
            $table->dropColumn('client');
        });
    }

    public function down(): void
    {
        Schema::table('employee_sea_services', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->dropColumn('client_id');
            $table->string('client', 255)->nullable();
        });
    }
};
