<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->normalizeLegacySoftDeletedHotels();
        $this->normalizeLegacySoftDeletedRoomTypes();

        if (Schema::hasColumn('hotels', 'deleted_at')) {
            Schema::table('hotels', function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }

        if (Schema::hasColumn('room_types', 'deleted_at')) {
            Schema::table('room_types', function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('hotels', 'deleted_at')) {
            Schema::table('hotels', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }

        if (! Schema::hasColumn('room_types', 'deleted_at')) {
            Schema::table('room_types', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }
    }

    private function normalizeLegacySoftDeletedHotels(): void
    {
        if (! Schema::hasColumn('hotels', 'deleted_at')) {
            return;
        }

        $referencedHotelIds = DB::table('crew_accommodation_stays')
            ->whereNotNull('hotel_id')
            ->distinct()
            ->pluck('hotel_id');

        if ($referencedHotelIds->isNotEmpty()) {
            DB::table('hotels')
                ->whereNotNull('deleted_at')
                ->whereIn('id', $referencedHotelIds)
                ->update([
                    'deleted_at' => null,
                    'is_active' => false,
                    'updated_at' => now(),
                ]);
        }

        $unusedSoftDeletedHotels = DB::table('hotels')
            ->whereNotNull('deleted_at');

        if ($referencedHotelIds->isNotEmpty()) {
            $unusedSoftDeletedHotels->whereNotIn('id', $referencedHotelIds);
        }

        $unusedSoftDeletedHotels->delete();
    }

    private function normalizeLegacySoftDeletedRoomTypes(): void
    {
        if (! Schema::hasColumn('room_types', 'deleted_at')) {
            return;
        }

        $referencedRoomTypeIds = DB::table('crew_accommodation_stays')
            ->whereNotNull('room_type_id')
            ->distinct()
            ->pluck('room_type_id');

        if ($referencedRoomTypeIds->isNotEmpty()) {
            DB::table('room_types')
                ->whereNotNull('deleted_at')
                ->whereIn('id', $referencedRoomTypeIds)
                ->update([
                    'deleted_at' => null,
                    'is_active' => false,
                    'updated_at' => now(),
                ]);
        }

        $unusedSoftDeletedRoomTypes = DB::table('room_types')
            ->whereNotNull('deleted_at');

        if ($referencedRoomTypeIds->isNotEmpty()) {
            $unusedSoftDeletedRoomTypes->whereNotIn('id', $referencedRoomTypeIds);
        }

        $unusedSoftDeletedRoomTypes->delete();
    }
};
