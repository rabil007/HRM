<?php

namespace App\Support\Ai;

use Laravel\Ai\Responses\StructuredAgentResponse;

final class StructuredAgentOutput
{
    /**
     * @return array<string, mixed>
     */
    public static function fromResponse(StructuredAgentResponse $response): array
    {
        $structured = $response->toArray();

        if ($structured !== []) {
            return $structured;
        }

        return self::decode((string) $response->text);
    }

    /**
     * @return array<string, mixed>
     */
    public static function decode(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        if (str_starts_with($text, '```')) {
            $text = (string) preg_replace('/^```(?:json)?\s*/i', '', $text);
            $text = (string) preg_replace('/\s*```$/', '', $text);
            $text = trim($text);
        }

        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : [];
    }
}
