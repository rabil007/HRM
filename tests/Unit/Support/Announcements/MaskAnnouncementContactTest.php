<?php

use App\Support\Announcements\MaskAnnouncementContact;

test('masks email and phone for announcement test destinations', function () {
    expect(MaskAnnouncementContact::email('work@company.test'))->toBe('w***@company.test')
        ->and(MaskAnnouncementContact::phone('971501234567'))->toBe('+971*******67')
        ->and(MaskAnnouncementContact::phone('+971501234567'))->toBe('+971*******67');
});
