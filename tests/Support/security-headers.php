<?php

use App\Support\Security\ContentSecurityPolicy;
use Illuminate\Testing\TestResponse;

function parseContentSecurityPolicy(string $header): array
{
    return ContentSecurityPolicy::parse($header);
}

function assertEnforcedContentSecurityPolicy(TestResponse $response): array
{
    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($csp)->not->toBeEmpty()
        ->and($response->headers->get('Content-Security-Policy-Report-Only'))->toBeNull();

    $directives = ContentSecurityPolicy::parse($csp);

    expect($directives['default-src'] ?? [])->toBe(["'self'"])
        ->and($directives['object-src'] ?? [])->toBe(["'none'"])
        ->and($directives['base-uri'] ?? [])->toBe(["'self'"])
        ->and($directives['frame-ancestors'] ?? [])->toBe(["'none'"])
        ->and($directives['form-action'] ?? [])->toBe(["'self'"])
        ->and($directives['script-src'] ?? [])->toContain("'self'")
        ->and($directives['script-src'] ?? [])->not->toContain("'unsafe-eval'")
        ->and($directives['script-src'] ?? [])->not->toContain("'unsafe-inline'")
        ->and($directives['connect-src'] ?? [])->toBe(["'self'"])
        ->and($directives['frame-src'] ?? [])->toBe(["'self'"])
        ->and($directives['img-src'] ?? [])->toContain('blob:')
        ->and($directives['worker-src'] ?? [])->toContain('blob:')
        ->and($csp)->not->toContain(' *')
        ->and($csp)->not->toContain('https:')
        ->and($csp)->not->toContain('wss:');

    return $directives;
}

function assertBrowserSecurityHeaders(TestResponse $response): void
{
    assertEnforcedContentSecurityPolicy($response);

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('X-Frame-Options', 'DENY');

    expect((string) $response->headers->get('Permissions-Policy'))
        ->toContain('camera=()')
        ->toContain('geolocation=()')
        ->toContain('microphone=()');
}
