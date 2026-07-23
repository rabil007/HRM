<?php

namespace App\Http\Requests\Settings;

use App\Support\Settings\SettingKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class UpdateApplicationBrandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('settings.application.update');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $imageRule = ['nullable', 'file', 'max:2048', 'mimes:png,jpg,jpeg,svg'];

        return [
            'main_logo' => $imageRule,
            'sidebar_logo' => $imageRule,
            'login_logo' => $imageRule,
            'favicon' => ['nullable', 'file', 'max:512', 'mimes:png,jpg,jpeg,svg,ico'],
            'login_background' => $imageRule,
            'primary_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'accent_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'sidebar_compact_default' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string|null> */
    public function colorPayload(): array
    {
        $payload = [];

        if ($this->filled('primary_color')) {
            $payload[SettingKey::PrimaryColor] = $this->string('primary_color')->toString();
        }

        if ($this->filled('accent_color')) {
            $payload[SettingKey::AccentColor] = $this->string('accent_color')->toString();
        }

        if ($this->has('sidebar_compact_default')) {
            $payload[SettingKey::SidebarCompactDefault] = $this->boolean('sidebar_compact_default') ? '1' : '0';
        }

        return $payload;
    }

    /** @return array<string, UploadedFile> */
    public function uploadFiles(): array
    {
        $map = [
            'main_logo' => SettingKey::MainLogo,
            'sidebar_logo' => SettingKey::SidebarLogo,
            'login_logo' => SettingKey::LoginLogo,
            'favicon' => SettingKey::Favicon,
            'login_background' => SettingKey::LoginBackground,
        ];

        $files = [];

        foreach ($map as $input => $key) {
            if ($this->hasFile($input)) {
                $files[$key] = $this->file($input);
            }
        }

        return $files;
    }
}
