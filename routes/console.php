<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule as Scheduler;
use Illuminate\Support\Facades\Schedule;

Schedule::command('notifications:retry')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('jobs:linkedin-drafts')
    ->days([Scheduler::MONDAY, Scheduler::THURSDAY])
    ->at('11:55')
    ->timezone((string) config('notifications.linkedin_drafts.timezone'))
    ->withoutOverlapping();
