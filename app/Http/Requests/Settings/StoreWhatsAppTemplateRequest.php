<?php

namespace App\Http\Requests\Settings;

class StoreWhatsAppTemplateRequest extends WhatsAppTemplateRequest
{
    protected function permission(): string
    {
        return 'settings.integrations.whatsapp-templates.create';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->templateRules();
    }
}
