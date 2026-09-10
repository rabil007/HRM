<?php

namespace App\Enums;

enum AnnouncementAiAssistAction: string
{
    case Generate = 'generate';
    case Improve = 'improve';
    case MakeProfessional = 'make_professional';
    case MakeFriendly = 'make_friendly';
    case Shorten = 'shorten';
    case FixGrammar = 'fix_grammar';
    case CreateWhatsAppVersion = 'create_whatsapp_version';
    case SuggestTemplate = 'suggest_template';

    public function label(): string
    {
        return match ($this) {
            self::Generate => 'Generate from instructions',
            self::Improve => 'Improve writing',
            self::MakeProfessional => 'Make professional',
            self::MakeFriendly => 'Make friendly',
            self::Shorten => 'Shorten',
            self::FixGrammar => 'Fix grammar',
            self::CreateWhatsAppVersion => 'Create WhatsApp version',
            self::SuggestTemplate => 'Suggest WhatsApp template',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
