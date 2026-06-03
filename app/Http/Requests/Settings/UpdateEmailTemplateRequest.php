<?php

namespace App\Http\Requests\Settings;

use App\Models\EmailTemplate;

class UpdateEmailTemplateRequest extends EmailTemplateRequest
{
    protected function permission(): string
    {
        return 'settings.integrations.email-templates.update';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var EmailTemplate $template */
        $template = $this->route('email_template');

        return $this->templateRules($template->id);
    }
}
