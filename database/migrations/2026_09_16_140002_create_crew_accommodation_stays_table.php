<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crew_accommodation_stays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('crew_assignment_id')->constrained('crew_assignments')->cascadeOnDelete();
            $table->foreignId('hotel_id')->nullable()->constrained('hotels')->nullOnDelete();
            $table->foreignId('room_type_id')->nullable()->constrained('room_types')->nullOnDelete();
            $table->string('stay_type', 32);
            $table->string('accommodation_status', 32);
            $table->date('check_in_date')->nullable();
            $table->date('check_out_date')->nullable();
            $table->foreignId('started_from_phase_id')->nullable()->constrained('crew_assignment_phases')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('company_id');
            $table->index('crew_assignment_id');
            $table->index('hotel_id');
            $table->index('room_type_id');
            $table->index('stay_type');
            $table->index('check_in_date');
            $table->index('check_out_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_accommodation_stays');
    }
};
