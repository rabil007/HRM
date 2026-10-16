<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_accommodation_stays', function (Blueprint $table): void {
            $table->unsignedInteger('edit_version')->default(0)->after('updated_by');
        });
    }

    public function down(): void
    {
        Schema::table('crew_accommodation_stays', function (Blueprint $table): void {
            $table->dropColumn('edit_version');
        });
    }
};
