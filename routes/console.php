<?php

use Illuminate\Support\Facades\Schedule;

/*
| Scheduled tasks: run by the "scheduler" container in production (schedule:work)
| and by `php artisan schedule:work` locally.
*/

Schedule::command('quotes:check-expiry')->dailyAt('07:30')->timezone('Europe/Rome')->withoutOverlapping();

Schedule::command('queue:prune-failed --hours=720')->weekly();
