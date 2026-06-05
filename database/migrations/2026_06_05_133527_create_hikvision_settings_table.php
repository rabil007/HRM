<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('hikvision_settings', function (Blueprint $table) {
            $table->id();
            $table->string('api_host')->nullable();
            $table->text('api_key')->nullable();
            $table->text('api_secret')->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });

        DB::table('hikvision_settings')->insert([
            'id' => 1,
            'enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hikvision_settings');
    }
};
