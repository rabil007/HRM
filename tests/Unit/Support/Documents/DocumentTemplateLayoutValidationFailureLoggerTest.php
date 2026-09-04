<?php

use App\Support\Documents\DocumentTemplateLayoutValidationFailureLogger;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

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

test('extracts sanitized process stderr without the command line', function () {
    $captured = null;

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context) use (&$captured): bool {
            $captured = $context;

            return true;
        });

    $process = new Process(['php', '-r', 'fwrite(STDERR, "Failed to launch browser at /opt/chrome for 784-2000-1234567-1"); exit(1);']);
    $process->run();
    $exception = new ProcessFailedException($process);

    (new DocumentTemplateLayoutValidationFailureLogger)->record($exception, 'LAY-01PROCESS', [
        'company_id' => 1,
        'template_id' => 2,
        'template_version_id' => 3,
        'validation_mode' => 'sample',
    ]);

    expect($captured['cause'])->toBe(ProcessFailedException::class)
        ->and($captured['process_exit_code'])->toBe(1)
        ->and($captured['process_error_output'])->toContain('Failed to launch browser')
        ->and($captured['process_error_output'])->not->toContain('/opt/chrome')
        ->and($captured['process_error_output'])->not->toContain('784-2000-1234567-1')
        ->and(json_encode($captured))->not->toContain('--html');
});
