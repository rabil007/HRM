<?php

use App\Enums\AnnouncementWhatsAppPayloadProfile;
use App\Enums\AnnouncementWhatsAppTemplatePurpose;
use App\Enums\WhatsAppTemplateCategory;
use App\Enums\WhatsAppTemplateHeaderType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Canonical Meta-approved Announcement WhatsApp templates (platform-global).
     *
     * @var list<array{
     *     slug: string,
     *     label: string,
     *     meta_name: string,
     *     body_preview: string,
     *     purpose: string,
     *     is_default: bool,
     *     sort_order: int
     * }>
     */
    private const TEMPLATES = [
        [
            'slug' => 'announcement_general',
            'label' => 'General Announcement',
            'meta_name' => 'employee_general_announcement',
            'body_preview' => "Update from OMS:\n\n{{1}}\n\nThank you.",
            'purpose' => 'general',
            'is_default' => true,
            'sort_order' => 10,
        ],
        [
            'slug' => 'announcement_promotion',
            'label' => 'Promotion Announcement',
            'meta_name' => 'employee_promotion_announcement',
            'body_preview' => "Here's an update from OMS:\n\n{{1}}\n\nThank you.",
            'purpose' => 'promotion',
            'is_default' => false,
            'sort_order' => 20,
        ],
        [
            'slug' => 'announcement_action_required',
            'label' => 'Action Required',
            'meta_name' => 'employee_action_required',
            'body_preview' => "Action required:\n\n{{1}}\n\nThank you.",
            'purpose' => 'action_required',
            'is_default' => false,
            'sort_order' => 30,
        ],
        [
            'slug' => 'announcement_reminder',
            'label' => 'Reminder',
            'meta_name' => 'employee_reminder',
            'body_preview' => "Reminder:\n\n{{1}}\n\nThank you.",
            'purpose' => 'reminder',
            'is_default' => false,
            'sort_order' => 40,
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('whatsapp_templates')) {
            return;
        }

        $now = now();
        $obsoletePurposes = ['internal', 'safety', 'crew', 'training'];
        $canonicalPurposes = array_column(AnnouncementWhatsAppTemplatePurpose::cases(), 'value');

        // Purpose is recommendation metadata only — normalize unsupported historical values
        // before the tightened enum cast can throw on load.
        DB::table('whatsapp_templates')
            ->where('category', WhatsAppTemplateCategory::Announcement->value)
            ->whereNotNull('purpose')
            ->where(function ($query) use ($obsoletePurposes, $canonicalPurposes): void {
                $query->whereIn('purpose', $obsoletePurposes)
                    ->orWhereNotIn('purpose', $canonicalPurposes);
            })
            ->update([
                'purpose' => null,
                'updated_at' => $now,
            ]);

        // Legacy V1 stays available for announcements with null whatsapp_template_id,
        // but must not remain the Announcement category default or an AI purpose match.
        DB::table('whatsapp_templates')
            ->where('slug', 'announcement')
            ->update([
                'is_default' => false,
                'purpose' => null,
                'updated_at' => $now,
            ]);

        foreach (self::TEMPLATES as $template) {
            $attributes = [
                'label' => $template['label'],
                'category' => WhatsAppTemplateCategory::Announcement->value,
                'meta_name' => $template['meta_name'],
                'meta_language' => 'en',
                'header_type' => WhatsAppTemplateHeaderType::Text->value,
                'payload_profile' => AnnouncementWhatsAppPayloadProfile::TitleBodyV2->value,
                'purpose' => $template['purpose'],
                'body_preview' => $template['body_preview'],
                'is_default' => $template['is_default'],
                'enabled' => true,
                'sort_order' => $template['sort_order'],
                'updated_at' => $now,
            ];

            $existing = DB::table('whatsapp_templates')
                ->where('slug', $template['slug'])
                ->first();

            if ($existing !== null) {
                DB::table('whatsapp_templates')
                    ->where('id', $existing->id)
                    ->update($attributes);

                continue;
            }

            DB::table('whatsapp_templates')->insert([
                ...$attributes,
                'slug' => $template['slug'],
                'created_at' => $now,
            ]);
        }

        // Ensure exactly one Announcement default: General Announcement.
        DB::table('whatsapp_templates')
            ->where('category', WhatsAppTemplateCategory::Announcement->value)
            ->where('slug', '!=', 'announcement_general')
            ->update([
                'is_default' => false,
                'updated_at' => $now,
            ]);

        DB::table('whatsapp_templates')
            ->where('slug', 'announcement_general')
            ->update([
                'is_default' => true,
                'updated_at' => $now,
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('whatsapp_templates')) {
            return;
        }

        $slugs = array_column(self::TEMPLATES, 'slug');
        $now = now();

        $referencedIds = [];

        if (Schema::hasTable('announcements') && Schema::hasColumn('announcements', 'whatsapp_template_id')) {
            $referencedIds = DB::table('announcements')
                ->whereIn('whatsapp_template_id', function ($query) use ($slugs): void {
                    $query->select('id')
                        ->from('whatsapp_templates')
                        ->whereIn('slug', $slugs);
                })
                ->pluck('whatsapp_template_id')
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        // Keep referenced rows for announcement FK history, but normalize fields that older
        // application versions cannot hydrate (e.g. purpose = action_required / reminder).
        if ($referencedIds !== []) {
            DB::table('whatsapp_templates')
                ->whereIn('id', $referencedIds)
                ->update([
                    'purpose' => null,
                    'is_default' => false,
                    'updated_at' => $now,
                ]);
        }

        DB::table('whatsapp_templates')
            ->whereIn('slug', $slugs)
            ->when($referencedIds !== [], fn ($query) => $query->whereNotIn('id', $referencedIds))
            ->delete();
    }
};
