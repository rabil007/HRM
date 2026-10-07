<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Allow partial Draft requirements to omit client and date fields until submission.
 * Existing non-Draft rows keep their populated values; only new Drafts may be null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_requirements', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->change();
            $table->date('request_received_date')->nullable()->change();
            $table->date('required_by_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        $hasPartialDraftRows = DB::table('recruitment_requirements')
            ->where(function ($query): void {
                $query->whereNull('client_id')
                    ->orWhereNull('request_received_date')
                    ->orWhereNull('required_by_date');
            })
            ->exists();

        if ($hasPartialDraftRows) {
            throw new RuntimeException(
                'Cannot roll back nullable recruitment requirement draft fields while partial Draft rows exist. Remove or complete those Drafts before rolling back this migration.',
            );
        }

        Schema::table('recruitment_requirements', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable(false)->change();
            $table->date('request_received_date')->nullable(false)->change();
            $table->date('required_by_date')->nullable(false)->change();
        });
    }
};
