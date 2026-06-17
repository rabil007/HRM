<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vessels', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->foreignId('vessel_type_id')->constrained('vessel_types')->restrictOnDelete();
            $table->decimal('grt', 12, 2)->nullable();
            $table->unsignedInteger('bhp')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique('name', 'uq_vessel_records_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vessels');
    }
};
