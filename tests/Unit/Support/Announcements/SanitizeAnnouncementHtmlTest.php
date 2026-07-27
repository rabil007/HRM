<?php

use App\Support\Announcements\SanitizeAnnouncementHtml;

test('keeps safe http links with target and rel', function () {
    $html = SanitizeAnnouncementHtml::handle(
        '<p>Read <a href="https://example.com/policy">the policy</a>.</p>',
    );

    expect($html)
        ->toContain('href="https://example.com/policy"')
        ->toContain('target="_blank"')
        ->toContain('rel="noopener noreferrer"')
        ->toContain('>the policy</a>');
});

test('strips unsafe javascript links', function () {
    $html = SanitizeAnnouncementHtml::handle(
        '<p><a href="javascript:alert(1)">bad</a></p>',
    );

    expect($html)
        ->not->toContain('javascript:')
        ->not->toContain('href=')
        ->toContain('bad');
});
