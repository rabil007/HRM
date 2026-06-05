<?php

use App\Models\EmailTemplate;
use App\Services\Settings\SettingService;
use App\Support\EmployeeDocuments\DocumentExpiryAlertSchedule;
use App\Support\Settings\ApplicationTimezone;
use App\Support\Settings\SettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'documents.expiry_alert_template_slug' => 'document_expiry_alert',
        'documents.expiry_alert_dispatch_at' => '08:00',
        'app.timezone' => 'UTC',
    ]);

    app(SettingService::class)->set(SettingKey::Timezone, 'Asia/Dubai');
});

test('dispatch at uses template time when set', function () {
    seedDocumentExpiryAlertTemplate(['dispatch_at' => '10:30']);

    expect(DocumentExpiryAlertSchedule::dispatchAt())->toBe('10:30');
});

test('dispatch at falls back to config when template time is missing', function () {
    seedDocumentExpiryAlertTemplate(['dispatch_at' => null]);

    expect(DocumentExpiryAlertSchedule::dispatchAt())->toBe('08:00');
});

test('dispatch at falls back to config when database is unavailable', function () {
    config(['documents.expiry_alert_dispatch_at' => '09:15']);

    DB::shouldReceive('connection')->andThrow(new RuntimeException('Connection refused'));

    expect(DocumentExpiryAlertSchedule::dispatchAt())->toBe('09:15');
});

test('timezone uses application regional settings not only config app timezone', function () {
    config(['app.timezone' => 'UTC']);

    expect(ApplicationTimezone::identifier())->toBe('Asia/Dubai')
        ->and(DocumentExpiryAlertSchedule::timezone())->toBe('Asia/Dubai');
});

/**
 * @param  array{dispatch_at?: string|null}  $overrides
 */
function seedDocumentExpiryAlertTemplate(array $overrides = []): void
{
    EmailTemplate::query()->updateOrCreate(
        ['slug' => 'document_expiry_alert'],
        array_merge([
            'label' => 'Document expiry alert',
            'category' => 'notification',
            'to_preset' => 'hr@example.com',
            'subject' => 'Document Expiry Alert',
            'body_html' => 'Automated expiry summary.',
            'is_default' => true,
            'enabled' => true,
            'sort_order' => 0,
            'dispatch_at' => '08:00',
        ], $overrides),
    );
}
