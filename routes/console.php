<?php

use App\Support\EmployeeDocuments\DocumentExpiryAlertSchedule;
use App\Support\Hikvision\HikvisionAccessEventsFetchSchedule;
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
