<?php

use App\Models\AppSetting;
use App\Models\AttendanceRecord;
use App\Models\EmployeeDocumentExpiryAlert;
use App\Models\HikvisionAccessEvent;
use App\Models\HikvisionDevice;
use App\Models\HikvisionPerson;
use App\Models\HikvisionPersonGroup;
use App\Models\HikvisionSetting;
use App\Models\LeaveBalance;
use App\Models\WhatsAppSetting;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

test('domain models use soft deletes', function (string $modelClass) {
    expect(in_array(SoftDeletes::class, class_uses_recursive($modelClass), true))->toBeTrue();
})->with([
    AttendanceRecord::class,
    AppSetting::class,
    EmployeeDocumentExpiryAlert::class,
    HikvisionAccessEvent::class,
    HikvisionDevice::class,
    HikvisionPerson::class,
    HikvisionPersonGroup::class,
    HikvisionSetting::class,
    WhatsAppSetting::class,
    LeaveBalance::class,
]);

test('newly soft-deleted tables have deleted_at column', function (string $table) {
    expect(Schema::hasColumn($table, 'deleted_at'))->toBeTrue();
})->with([
    'attendance_records',
    'app_settings',
    'employee_document_expiry_alerts',
    'hikvision_access_events',
    'hikvision_devices',
    'hikvision_persons',
    'hikvision_person_groups',
    'hikvision_settings',
    'whatsapp_settings',
    'leave_balances',
]);
