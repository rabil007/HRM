<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_runs', function (Blueprint $table) {
            $table->id();
            $table->string('correlation_id')->nullable()->index();
            $table->string('type', 20);
            $table->string('name');
            $table->string('status', 20);
            $table->string('queue')->nullable();
            $table->string('connection')->nullable();
            $table->string('trigger', 20)->nullable();
            $table->json('context')->nullable();
            $table->text('message')->nullable();
            $table->longText('exception')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['status', 'started_at']);
            $table->index(['name', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_runs');
    }
};
