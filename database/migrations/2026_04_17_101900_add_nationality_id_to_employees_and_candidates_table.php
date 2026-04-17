<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table
                ->foreignId('nationality_id')
                ->nullable()
                ->constrained('countries')
                ->nullOnDelete()
                ->after('gender');

            $table->dropColumn('nationality');
        });

        Schema::table('candidates', function (Blueprint $table) {
            $table
                ->foreignId('nationality_id')
                ->nullable()
                ->constrained('countries')
                ->nullOnDelete()
                ->after('phone');

            $table->dropColumn('nationality');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('nationality', 100)->nullable()->after('gender');
            $table->dropConstrainedForeignId('nationality_id');
        });

        Schema::table('candidates', function (Blueprint $table) {
            $table->string('nationality', 100)->nullable()->after('phone');
            $table->dropConstrainedForeignId('nationality_id');
        });
    }
};
