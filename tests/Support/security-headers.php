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
        ->and($directives['frame-ancestors'] ?? [])->toBe(ContentSecurityPolicy::frameAncestors())
        ->and($directives['form-action'] ?? [])->toBe(["'self'"])
        ->and($directives['script-src'] ?? [])->toContain("'self'")
        ->and($directives['script-src'] ?? [])->not->toContain("'unsafe-eval'")
        ->and($directives['script-src'] ?? [])->not->toContain("'unsafe-inline'")
        ->and($directives['script-src'] ?? [])->not->toContain('https:')
        ->and($directives['script-src'] ?? [])->not->toContain('*')
        ->and($directives['connect-src'] ?? [])->toBe(["'self'"])
        ->and($directives['frame-src'] ?? [])->toBe(["'self'"])
        ->and($directives['img-src'] ?? [])->toContain('blob:')
        ->and($directives['worker-src'] ?? [])->toContain('blob:')
        ->and($csp)->not->toContain(' *')
        ->and($csp)->not->toContain('wss:');

    return $directives;
}

function assertBrowserSecurityHeaders(TestResponse $response): void
{
    assertEnforcedContentSecurityPolicy($response);

    $expectedFrameOptions = config('security.headers.x_frame_options', 'SAMEORIGIN');

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    if ($expectedFrameOptions === false || $expectedFrameOptions === null || in_array(strtolower((string) $expectedFrameOptions), ['false', 'off', 'none'], true)) {
        $response->assertHeaderMissing('X-Frame-Options');
    } else {
        $response->assertHeader('X-Frame-Options', (string) $expectedFrameOptions);
    }

    expect((string) $response->headers->get('Permissions-Policy'))
        ->toContain('camera=()')
        ->toContain('geolocation=()')
        ->toContain('microphone=()');
}
