<?php

use Carbon\Carbon;
use Carbon\CarbonImmutable;

function freezeOrganizationMovementTestClock(): void
{
    Carbon::setTestNow(Carbon::parse('2027-01-15 12:00:00', 'Asia/Dubai'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-01-15 12:00:00', 'Asia/Dubai'));
}

function restoreOrganizationTestClock(): void
{
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
}

function freezeCrewMovementTestClock(): void
{
    freezeOrganizationMovementTestClock();
}

function restoreCrewMovementTestClock(): void
{
    restoreOrganizationTestClock();
}

trait WithCrewMovementClock
{
    protected function setUpWithCrewMovementClock(): void
    {
        freezeCrewMovementTestClock();
    }

    protected function tearDownWithCrewMovementClock(): void
    {
        restoreCrewMovementTestClock();
    }
}
