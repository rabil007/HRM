<?php

namespace App\Http\Requests\Settings;

class UpdateWhatsAppTemplateRequest extends WhatsAppTemplateRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $template = $this->route('whatsapp_template');

        return $this->templateRules(is_object($template) ? $template->id : null);
    }
}
