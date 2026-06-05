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
        Schema::table('hikvision_access_events', function (Blueprint $table) {
            $table->string('person_name')->nullable()->after('resource_name');
            $table->string('door_no')->nullable()->after('person_name');
            $table->string('card_reader_no')->nullable()->after('door_no');
            $table->string('verify_mode')->nullable()->after('card_reader_no');
            $table->string('attendance_status')->nullable()->after('verify_mode');
            $table->string('event_source')->default('acs_isapi')->after('attendance_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hikvision_access_events', function (Blueprint $table) {
            $table->dropColumn([
                'person_name',
                'door_no',
                'card_reader_no',
                'verify_mode',
                'attendance_status',
                'event_source',
            ]);
        });
    }
};
