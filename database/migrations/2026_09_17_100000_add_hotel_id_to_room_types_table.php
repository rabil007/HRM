<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropUnique('uq_room_types_company_name');

            $table->foreignId('hotel_id')
                ->nullable()
                ->after('company_id')
                ->constrained('hotels')
                ->restrictOnDelete();

            $table->unique(['company_id', 'hotel_id', 'name'], 'uq_room_types_company_hotel_name');
            $table->index(['company_id', 'hotel_id']);
        });
    }

    public function down(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropUnique('uq_room_types_company_hotel_name');
            $table->dropIndex(['company_id', 'hotel_id']);

            $table->dropConstrainedForeignId('hotel_id');

            $table->unique(['company_id', 'name'], 'uq_room_types_company_name');
        });
    }
};
