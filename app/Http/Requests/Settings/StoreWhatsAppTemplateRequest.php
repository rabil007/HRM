<?php

namespace App\Http\Requests\Settings;

class StoreWhatsAppTemplateRequest extends WhatsAppTemplateRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->templateRules();
    }
}
