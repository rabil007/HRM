<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_requirement_lines', function (Blueprint $table) {
            $table->decimal('salary_min', 12, 2)->nullable()->after('required_headcount');
            $table->decimal('salary_max', 12, 2)->nullable()->after('salary_min');
            $table->string('salary_currency_code', 3)->nullable()->after('salary_max');
        });
    }

    public function down(): void
    {
        Schema::table('recruitment_requirement_lines', function (Blueprint $table) {
            $table->dropColumn(['salary_min', 'salary_max', 'salary_currency_code']);
        });
    }
};
