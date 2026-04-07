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
        Schema::create('job_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->decimal('offered_salary', 12, 2);
            $table->decimal('housing_allowance', 12, 2)->default(0);
            $table->decimal('transport_allowance', 12, 2)->default(0);
            $table->decimal('other_allowances', 12, 2)->default(0);
            $table->date('joining_date');
            $table->enum('contract_type', ['limited', 'unlimited', 'part_time', 'contract'])->default('unlimited');
            $table->string('offer_letter_path', 500)->nullable();
            $table->date('expiry_date')->nullable();
            $table->enum('status', ['pending', 'accepted', 'rejected', 'expired', 'withdrawn'])->default('pending');
            $table->timestamp('responded_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_offers');
    }
};
