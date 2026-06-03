<?php

namespace App\Http\Requests\Settings;

class UpdateWhatsAppTemplateRequest extends WhatsAppTemplateRequest
{
    protected function permission(): string
    {
        return 'settings.integrations.whatsapp-templates.update';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $template = $this->route('whatsapp_template');

        return $this->templateRules(is_object($template) ? $template->id : null);
    }
}
