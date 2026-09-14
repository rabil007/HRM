<?php

use App\Models\CompanyDocumentExpiryNotificationSetting;
use App\Models\User;
use App\Support\Migrations\EnsureCompanyDocumentExpiryNotificationRecipientsTable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('recipient table repair does not drop existing recipient rows', function () {
    ['company' => $company] = makeDocumentFixtures();
    $user = User::factory()->create();
    $setting = CompanyDocumentExpiryNotificationSetting::query()->create([
        'company_id' => $company->id,
        'enabled' => true,
    ]);

    $recipientId = DB::table(EnsureCompanyDocumentExpiryNotificationRecipientsTable::TABLE)->insertGetId([
        'setting_id' => $setting->id,
        'user_id' => $user->id,
        'type' => 'to',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    EnsureCompanyDocumentExpiryNotificationRecipientsTable::up();
    EnsureCompanyDocumentExpiryNotificationRecipientsTable::up();

    expect(DB::table(EnsureCompanyDocumentExpiryNotificationRecipientsTable::TABLE)->where('id', $recipientId)->exists())->toBeTrue()
        ->and(Schema::hasTable(EnsureCompanyDocumentExpiryNotificationRecipientsTable::TABLE))->toBeTrue()
        ->and(Schema::hasIndex(
            EnsureCompanyDocumentExpiryNotificationRecipientsTable::TABLE,
            EnsureCompanyDocumentExpiryNotificationRecipientsTable::UNIQUE_INDEX,
        ))->toBeTrue();
});

test('partial recipients table with rows is repaired without losing data', function () {
    ['company' => $company] = makeDocumentFixtures();
    $user = User::factory()->create();
    $setting = CompanyDocumentExpiryNotificationSetting::query()->create([
        'company_id' => $company->id,
        'enabled' => true,
    ]);

    Schema::dropIfExists(EnsureCompanyDocumentExpiryNotificationRecipientsTable::TABLE);

    Schema::create(EnsureCompanyDocumentExpiryNotificationRecipientsTable::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('setting_id');
        $table->unsignedBigInteger('user_id');
        $table->string('type')->default('to');
        $table->timestamps();
    });

    $recipientId = DB::table(EnsureCompanyDocumentExpiryNotificationRecipientsTable::TABLE)->insertGetId([
        'setting_id' => $setting->id,
        'user_id' => $user->id,
        'type' => 'cc',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    EnsureCompanyDocumentExpiryNotificationRecipientsTable::up();

    $row = DB::table(EnsureCompanyDocumentExpiryNotificationRecipientsTable::TABLE)->where('id', $recipientId)->first();

    expect($row)->not->toBeNull()
        ->and($row->type)->toBe('cc')
        ->and($row->user_id)->toBe($user->id)
        ->and(Schema::hasIndex(
            EnsureCompanyDocumentExpiryNotificationRecipientsTable::TABLE,
            EnsureCompanyDocumentExpiryNotificationRecipientsTable::UNIQUE_INDEX,
        ))->toBeTrue()
        ->and(Schema::hasIndex(
            EnsureCompanyDocumentExpiryNotificationRecipientsTable::TABLE,
            EnsureCompanyDocumentExpiryNotificationRecipientsTable::TYPE_INDEX,
        ))->toBeTrue();
});

test('empty broken recipients table can be recreated', function () {
    Schema::dropIfExists(EnsureCompanyDocumentExpiryNotificationRecipientsTable::TABLE);

    Schema::create(EnsureCompanyDocumentExpiryNotificationRecipientsTable::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('legacy_only')->nullable();
    });

    EnsureCompanyDocumentExpiryNotificationRecipientsTable::up();

    expect(Schema::hasColumns(EnsureCompanyDocumentExpiryNotificationRecipientsTable::TABLE, [
        'setting_id',
        'user_id',
        'type',
    ]))->toBeTrue();
});
