<?php

use App\Enums\SavedViewPage;

test('saved view pages map to existing named index routes', function () {
    expect(SavedViewPage::Employees->routeName())->toBe('organization.employees')
        ->and(SavedViewPage::Documents->routeName())->toBe('organization.documents.library')
        ->and(SavedViewPage::Crew->routeName())->toBe('organization.crew-assignments.index')
        ->and(SavedViewPage::Leave->routeName())->toBe('attendance.leave-requests.index')
        ->and(SavedViewPage::Payroll->routeName())->toBe('payroll.index');
});
