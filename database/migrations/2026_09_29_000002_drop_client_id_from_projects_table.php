<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::withoutForeignKeyConstraints(function (): void {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropConstrainedForeignId('client_id');
            });
        });
    }

    public function down(): void
    {
        // Read existing pivot associations BEFORE schema alteration
        // (guarantees deterministic rollback data across all database engines including SQLite table rebuilds)
        $pivotRecords = DB::table('client_project')
            ->select('project_id', DB::raw('MIN(client_id) as client_id'))
            ->groupBy('project_id')
            ->get();

        Schema::withoutForeignKeyConstraints(function (): void {
            Schema::table('projects', function (Blueprint $table) {
                $table->foreignId('client_id')
                    ->nullable()
                    ->constrained('clients')
                    ->restrictOnDelete();
            });
        });

        foreach ($pivotRecords as $record) {
            DB::table('projects')
                ->where('id', $record->project_id)
                ->update(['client_id' => (int) $record->client_id]);
        }
    }
};
