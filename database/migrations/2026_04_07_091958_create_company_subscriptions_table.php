<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('company_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('subscription_plans');
            $table->enum('billing_cycle', ['monthly', 'yearly'])->default('monthly');
            $table->date('starts_at');
            $table->date('ends_at');
            $table->decimal('price_paid', 10, 2)->default(0);
            $table->enum('status', ['active', 'expired', 'cancelled', 'trial'])->default('trial');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_subscriptions');
    }
};
