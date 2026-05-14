<?php

use App\Models\OnboardingTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('onboarding_template_id')
                ->nullable()
                ->after('company_id')
                ->constrained('onboarding_templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeignIdFor(OnboardingTemplate::class);
        });
    }
};
