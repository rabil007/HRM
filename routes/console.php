<?php

use App\Support\EmployeeDocuments\DocumentExpiryAlertSchedule;
use App\Support\Hikvision\HikvisionAccessEventsFetchSchedule;
use App\Support\Hikvision\HikvisionEveningAccessEventsFetchSchedule;
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
    ->dailyAt(HikvisionAccessEventsFetchSchedule::dispatchAt())
    ->timezone(HikvisionAccessEventsFetchSchedule::timezone())
    ->when(fn () => HikvisionAccessEventsFetchSchedule::isEnabled())
    ->withoutOverlapping();

Schedule::command('hikvision:fetch-todays-access-events')
    ->dailyAt(HikvisionEveningAccessEventsFetchSchedule::dispatchAt())
    ->timezone(HikvisionEveningAccessEventsFetchSchedule::timezone())
    ->when(fn () => HikvisionEveningAccessEventsFetchSchedule::isEnabled())
    ->name('hikvision-evening-access-events')
    ->withoutOverlapping();

Schedule::command('contracts:expire')
    ->dailyAt('01:00')
    ->timezone(config('app.timezone', 'UTC'))
    ->withoutOverlapping();
