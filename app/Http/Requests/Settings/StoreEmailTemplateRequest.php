<?php

namespace App\Http\Requests\Settings;

class StoreEmailTemplateRequest extends EmailTemplateRequest
{
    protected function permission(): string
    {
        return 'settings.integrations.email-templates.create';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->templateRules();
    }
}
