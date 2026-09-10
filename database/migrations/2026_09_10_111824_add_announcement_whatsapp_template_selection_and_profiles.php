<?php

use App\Enums\AnnouncementWhatsAppPayloadProfile;
use App\Enums\WhatsAppTemplateCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('whatsapp_templates', 'payload_profile')) {
                $table->string('payload_profile')
                    ->nullable()
                    ->after('header_type');
            }

            if (! Schema::hasColumn('whatsapp_templates', 'purpose')) {
                $table->string('purpose')
                    ->nullable()
                    ->after('payload_profile');
            }
        });

        Schema::table('announcements', function (Blueprint $table) {
            if (! Schema::hasColumn('announcements', 'whatsapp_template_id')) {
                $table->foreignId('whatsapp_template_id')
                    ->nullable()
                    ->after('whatsapp_message')
                    ->constrained('whatsapp_templates')
                    ->nullOnDelete();
            }
        });

        // Preserve the production legacy template as an Announcement-compatible option.
        DB::table('whatsapp_templates')
            ->where('slug', 'announcement')
            ->update([
                'category' => WhatsAppTemplateCategory::Announcement->value,
                'payload_profile' => AnnouncementWhatsAppPayloadProfile::LegacyV1->value,
                'purpose' => 'general',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            if (Schema::hasColumn('announcements', 'whatsapp_template_id')) {
                $table->dropConstrainedForeignId('whatsapp_template_id');
            }
        });

        DB::table('whatsapp_templates')
            ->where('slug', 'announcement')
            ->update([
                'category' => WhatsAppTemplateCategory::General->value,
                'payload_profile' => null,
                'purpose' => null,
                'updated_at' => now(),
            ]);

        Schema::table('whatsapp_templates', function (Blueprint $table) {
            if (Schema::hasColumn('whatsapp_templates', 'purpose')) {
                $table->dropColumn('purpose');
            }

            if (Schema::hasColumn('whatsapp_templates', 'payload_profile')) {
                $table->dropColumn('payload_profile');
            }
        });
    }
};
