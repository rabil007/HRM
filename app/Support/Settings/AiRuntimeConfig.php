<?php

namespace App\Support\Settings;

final readonly class AiRuntimeConfig
{
    public function __construct(
        public string $provider,
        public ?string $model,
        public string $apiKey,
    ) {}
}
