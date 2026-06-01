<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_contracts', function (Blueprint $table) {
            $table->decimal('supplementary_allowance', 12, 2)
                ->nullable()
                ->after('other_allowances');
            $table->decimal('site_allowance', 12, 2)
                ->nullable()
                ->after('supplementary_allowance');
        });
    }

    public function down(): void
    {
        Schema::table('employee_contracts', function (Blueprint $table) {
            $table->dropColumn([
                'supplementary_allowance',
                'site_allowance',
            ]);
        });
    }
};
