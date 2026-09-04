<?php

use App\Support\Documents\DocumentTemplateLayoutValidationFailureLogger;
use Illuminate\Support\Facades\Log;

test('sanitizes filesystem paths and emirates-id shaped values from messages', function () {
    $message = DocumentTemplateLayoutValidationFailureLogger::sanitizeMessage(
        'Failed /Users/ops/chrome 784-2000-1234567-1 at /var/www/html/source.pdf for nina.v@example.com',
    );

    expect($message)->not->toContain('/Users/ops/chrome')
        ->and($message)->not->toContain('784-2000-1234567-1')
        ->and($message)->not->toContain('nina.v@example.com')
        ->and($message)->toContain('[path]')
        ->and($message)->toContain('[redacted]');
});

test('records a diagnostic log without merge values or filesystem paths', function () {
    $captured = null;

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context) use (&$captured): bool {
            $captured = ['message' => $message, 'context' => $context];

            return true;
        });

    $previous = new RuntimeException('Chrome died at /opt/chrome with 784-2000-1234567-1');
    $exception = new RuntimeException('PDF overlay layout measurement failed.', 0, $previous);

    (new DocumentTemplateLayoutValidationFailureLogger)->record($exception, 'LAY-01TESTREFERENCE', [
        'company_id' => 9,
        'template_id' => 4,
        'template_version_id' => 2,
        'template_type' => 'pdf_overlay',
        'validation_mode' => 'sample',
        'user_id' => 11,
    ]);

    expect($captured)->not->toBeNull()
        ->and($captured['message'])->toBe(DocumentTemplateLayoutValidationFailureLogger::EVENT)
        ->and($captured['context']['reference_id'])->toBe('LAY-01TESTREFERENCE')
        ->and($captured['context']['company_id'])->toBe(9)
        ->and($captured['context']['template_id'])->toBe(4)
        ->and($captured['context']['version_id'])->toBe(2)
        ->and($captured['context']['exception'])->toBe(RuntimeException::class);

    $encoded = json_encode($captured['context']);

    expect($encoded)->not->toContain('784-2000-1234567-1')
        ->and($encoded)->not->toContain('/opt/chrome');
});
