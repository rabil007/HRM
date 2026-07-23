<?php

use App\Models\JobRun;
use App\Support\EmployeeDocuments\DocumentExpiryAlertSchedule;
use App\Support\Hikvision\HikvisionAccessEventsFetchSchedule;
use App\Support\Hikvision\HikvisionEveningAccessEventsFetchSchedule;
use App\Support\Settings\ApplicationTimezone;
use Illuminate\Support\Facades\Schedule;

Schedule::command('documents:dispatch-expiry-alerts')
    ->dailyAt(DocumentExpiryAlertSchedule::dispatchAt())
    ->timezone(DocumentExpiryAlertSchedule::timezone())
    ->withoutOverlapping();

Schedule::command('leave-balances:rollover')
    ->yearlyOn(1, 1, '00:30')
    ->timezone(config('app.timezone', 'UTC'))
    ->withoutOverlapping();

Schedule::command('hikvision:fetch-access-events')
    ->everyMinute()
    ->timezone(HikvisionAccessEventsFetchSchedule::timezone())
    ->when(fn () => HikvisionAccessEventsFetchSchedule::isEnabled())
    ->withoutOverlapping();

Schedule::command('hikvision:fetch-todays-access-events')
    ->everyMinute()
    ->timezone(HikvisionEveningAccessEventsFetchSchedule::timezone())
    ->when(fn () => HikvisionEveningAccessEventsFetchSchedule::isEnabled())
    ->name('hikvision-evening-access-events')
    ->withoutOverlapping();

Schedule::command('payroll:ensure-future-periods')
    ->dailyAt('00:45')
    ->timezone(config('app.timezone', 'UTC'))
    ->withoutOverlapping();

Schedule::command('announcements:publish-scheduled')
    ->everyMinute()
    ->timezone(config('app.timezone', 'UTC'))
    ->withoutOverlapping();

Schedule::command('contracts:expire')
    ->dailyAt('01:00')
    ->timezone(config('app.timezone', 'UTC'))
    ->withoutOverlapping();

Schedule::command('contracts:mirror-effective-salary-revisions')
    ->dailyAt('01:15')
    ->timezone(config('app.timezone', 'UTC'))
    ->withoutOverlapping();

Schedule::command('model:prune', [
    '--model' => [JobRun::class],
])
    ->dailyAt('02:00')
    ->timezone(ApplicationTimezone::identifier())
    ->withoutOverlapping()
    ->name('job-runs-prune');
