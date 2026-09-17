<?php

use App\Support\MasterData\ReconcileLegacyRoomTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

        ReconcileLegacyRoomTypes::autoReconcile();
    }

    public function down(): void
    {
        $duplicateNames = DB::table('room_types')
            ->select('company_id', 'name')
            ->whereNotNull('name')
            ->groupBy('company_id', 'name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicateNames->isNotEmpty()) {
            $examples = $duplicateNames
                ->take(5)
                ->map(fn (object $row): string => "company_id={$row->company_id}, name=\"{$row->name}\"")
                ->implode('; ');

            throw new RuntimeException(
                'Cannot roll back room_types hotel_id migration safely: duplicate room type names exist per company after hotel-specific naming was introduced. '
                ."Examples: {$examples}. Resolve or rename duplicates before rolling back."
            );
        }

        Schema::table('room_types', function (Blueprint $table) {
            $table->dropUnique('uq_room_types_company_hotel_name');
            $table->dropIndex(['company_id', 'hotel_id']);

            $table->dropConstrainedForeignId('hotel_id');

            $table->unique(['company_id', 'name'], 'uq_room_types_company_name');
        });
    }
};
