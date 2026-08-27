<?php

use App\Support\Security\ContentSecurityPolicy;

test('production csp is same-origin and never allows eval or wildcards', function () {
    $directives = ContentSecurityPolicy::directives('testing');
    $header = ContentSecurityPolicy::compile($directives);

    expect($directives['script-src'])->toBe(["'self'"])
        ->and($directives['style-src'])->toContain("'unsafe-inline'")
        ->and($directives['object-src'])->toBe(["'none'"])
        ->and($directives['frame-ancestors'])->toBe(["'self'", 'https://*.overseas-ms.com', 'https://overseas-ms.com'])
        ->and($header)->not->toContain('unsafe-eval')
        ->and($directives['connect-src'])->toBe(["'self'"])
        ->and($directives['img-src'])->toContain('blob:')
        ->and($directives['img-src'])->toContain('data:')
        ->and($directives['font-src'])->toBe(["'self'"])
        ->and($directives['media-src'])->toBe(["'self'"])
        ->and($directives['worker-src'])->toContain('blob:');
});

test('csp frame-ancestors parses custom string configuration', function () {
    config(['security.headers.csp.frame_ancestors' => "'self' https://portal.example.com https://*.example.com"]);

    expect(ContentSecurityPolicy::frameAncestors())->toBe([
        "'self'",
        'https://portal.example.com',
        'https://*.example.com',
    ]);
});

test('csp frame-ancestors supports none to deny all framing', function () {
    config(['security.headers.csp.frame_ancestors' => "'none'"]);

    expect(ContentSecurityPolicy::frameAncestors())->toBe(["'none'"]);
});

test('csp frame-ancestors falls back to self when empty', function () {
    config(['security.headers.csp.frame_ancestors' => '   ']);

    expect(ContentSecurityPolicy::frameAncestors())->toBe(["'self'"]);
});

test('local csp allows configured vite origins and inline scripts for hmr', function () {
    $directives = ContentSecurityPolicy::directives('local');

    expect($directives['script-src'])->toContain("'self'")
        ->and($directives['script-src'])->toContain("'unsafe-inline'")
        ->and($directives['script-src'])->toContain('https://oms-hrm.test:5173')
        ->and($directives['style-src'])->toContain('https://oms-hrm.test:5173')
        ->and($directives['font-src'])->toContain('https://oms-hrm.test:5173')
        ->and($directives['connect-src'])->toContain('wss://oms-hrm.test:5173')
        ->and($directives['script-src'])->not->toContain("'unsafe-eval'");
});

test('vite origins reject untrusted hosts', function () {
    config(['security.headers.csp.vite_dev_origins' => [
        'https://oms-hrm.test:5173',
        'https://evil.example:5173',
        'javascript:alert(1)',
        'https://127.0.0.1:5173',
    ]]);

    expect(ContentSecurityPolicy::viteDevOrigins())->toBe([
        'https://oms-hrm.test:5173',
        'https://127.0.0.1:5173',
    ]);
});
