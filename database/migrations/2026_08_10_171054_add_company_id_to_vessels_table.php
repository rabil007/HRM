<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vessels', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->after('id');
        });

        DB::table('vessels')->update(['company_id' => 1]);

        Schema::table('vessels', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable(false)->change();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->dropUnique('uq_vessel_records_name');
            $table->unique(['company_id', 'name'], 'uq_vessels_company_name');
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        $duplicateNames = DB::table('vessels')
            ->select('name', DB::raw('COUNT(*) as total'))
            ->groupBy('name')
            ->having('total', '>', 1)
            ->pluck('name');

        foreach ($duplicateNames as $name) {
            $vessels = DB::table('vessels')
                ->where('name', $name)
                ->orderBy('id')
                ->get(['id']);

            foreach ($vessels as $index => $vessel) {
                if ($index > 0) {
                    $suffix = ' ('.($index + 1).')';
                    DB::table('vessels')
                        ->where('id', $vessel->id)
                        ->update(['name' => $name.$suffix]);
                }
            }
        }

        Schema::table('vessels', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropUnique('uq_vessels_company_name');
            $table->dropIndex(['company_id']);
            $table->dropColumn('company_id');
            $table->unique('name', 'uq_vessel_records_name');
        });
    }
};
