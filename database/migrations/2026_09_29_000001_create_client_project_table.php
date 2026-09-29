<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_project', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')
                ->constrained('clients', 'id', 'fk_client_project_client')
                ->restrictOnDelete();
            $table->foreignId('project_id')
                ->constrained('projects', 'id', 'fk_client_project_project')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['client_id', 'project_id'], 'uq_client_project_client_project');
            $table->index('project_id', 'idx_client_project_project');
        });

        DB::table('projects')
            ->whereNotNull('client_id')
            ->orderBy('id')
            ->chunkById(500, function ($projects): void {
                $now = now();
                $rows = [];

                foreach ($projects as $project) {
                    $rows[] = [
                        'client_id' => (int) $project->client_id,
                        'project_id' => (int) $project->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('client_project')->insertOrIgnore($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_project');
    }
};
