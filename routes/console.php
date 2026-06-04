<?php

use App\Support\EmployeeDocuments\DocumentExpiryAlertSchedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('documents:dispatch-expiry-alerts')
    ->dailyAt(DocumentExpiryAlertSchedule::dispatchAt())
    ->timezone(DocumentExpiryAlertSchedule::timezone())
    ->withoutOverlapping()
    ->onOneServer();
