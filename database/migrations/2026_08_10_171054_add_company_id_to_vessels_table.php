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
        $duplicates = DB::table('vessels')
            ->select('name', DB::raw('COUNT(*) as total'))
            ->groupBy('name')
            ->having('total', '>', 1)
            ->orderBy('name')
            ->pluck('name');

        if ($duplicates->isNotEmpty()) {
            $examples = $duplicates->take(10)->implode(', ');
            $more = $duplicates->count() > 10
                ? ' (and '.($duplicates->count() - 10).' more)'
                : '';

            throw new RuntimeException(
                'Cannot roll back company-owned vessels because duplicate vessel names exist across companies: '
                .$examples.$more
                .'. The old global vessels.name uniqueness constraint cannot be restored until duplicate names are manually resolved. Vessel names were not modified.'
            );
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
