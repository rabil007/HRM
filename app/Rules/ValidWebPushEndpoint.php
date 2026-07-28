<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

class ValidWebPushEndpoint implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('The endpoint must be a valid HTTPS push URL.');

            return;
        }

        if (strlen($value) > 500) {
            $fail('The endpoint may not be greater than 500 characters.');

            return;
        }

        $parts = parse_url($value);

        if ($parts === false
            || ! isset($parts['scheme'], $parts['host'])
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            $fail('The endpoint must be a valid HTTPS push URL.');

            return;
        }

        if (strtolower((string) $parts['scheme']) !== 'https') {
            $fail('The endpoint must use HTTPS.');

            return;
        }

        $host = strtolower((string) $parts['host']);
        $host = Str::of($host)->trim('[]')->toString();

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            $fail('The endpoint host is not allowed.');

            return;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $fail('The endpoint host is not allowed.');

            return;
        }

        if (! config('webpush.validate_endpoint_dns', true)) {
            return;
        }

        if (! $this->isResolvablePublicHost($host)) {
            $fail('The endpoint host is not allowed.');
        }
    }

    private function isResolvablePublicHost(string $host): bool
    {
        $ipv4 = gethostbynamel($host) ?: [];
        $ipv6 = [];

        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_AAAA) ?: [];

            foreach ($records as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ipv6[] = $record['ipv6'];
                }
            }
        }

        $ips = array_values(array_unique([...$ipv4, ...$ipv6]));

        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if ($this->isBlockedIp($ip)) {
                return false;
            }
        }

        return true;
    }

    private function isBlockedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return true;
            }

            $long = ip2long($ip);

            if ($long === false) {
                return true;
            }

            // Multicast 224.0.0.0/4
            if (($long & 0xF0000000) === 0xE0000000) {
                return true;
            }

            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return true;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        $binary = @inet_pton($ip);

        if ($binary === false) {
            return true;
        }

        // IPv6 multicast ff00::/8
        if ((ord($binary[0]) & 0xFF) === 0xFF) {
            return true;
        }

        // Unique local fc00::/7
        if ((ord($binary[0]) & 0xFE) === 0xFC) {
            return true;
        }

        // Link-local fe80::/10
        if (ord($binary[0]) === 0xFE && (ord($binary[1]) & 0xC0) === 0x80) {
            return true;
        }

        return false;
    }
}
