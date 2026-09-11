<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vessels', function (Blueprint $table) {
            $table->foreignId('client_id')
                ->nullable()
                ->after('company_id')
                ->constrained('clients')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vessels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });
    }
};
