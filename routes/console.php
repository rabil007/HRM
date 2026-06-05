<?php

use App\Support\EmployeeDocuments\DocumentExpiryAlertSchedule;
use Illuminate\Support\Facades\Schedule;

Schedule::command('documents:dispatch-expiry-alerts')
    ->dailyAt(DocumentExpiryAlertSchedule::dispatchAt())
    ->timezone(DocumentExpiryAlertSchedule::timezone())
    ->withoutOverlapping();
