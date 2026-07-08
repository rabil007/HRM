<?php

use App\Support\Email\EmailTemplateBodyRenderer;

test('email template body renderer converts plain text line breaks to html', function () {
    $html = EmailTemplateBodyRenderer::toHtml("Dear Jane,\n\nPlease review.\n\nThanks");

    expect($html)
        ->toContain('Dear Jane,<br />')
        ->toContain('Please review.<br />')
        ->toContain('Thanks');
});

test('email template body renderer preserves html paragraphs', function () {
    $body = '<p style="margin:0 0 16px;">Dear {{employee_name}},</p>';

    expect(EmailTemplateBodyRenderer::toHtml($body))->toBe($body);
});
