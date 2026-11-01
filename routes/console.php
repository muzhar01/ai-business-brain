<?php

use Illuminate\Support\Facades\Schedule;

// Run `php artisan schedule:work` (dev) or a cron calling `schedule:run` every minute (prod).
// onOneServer() needs a shared cache (Redis) when several app servers run the scheduler.
Schedule::command('brain:briefings')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('brain:etl:scheduled')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('brain:retention')->dailyAt('03:15')->onOneServer();
Schedule::command('brain:audit:verify')->dailyAt('03:45')->onOneServer();
Schedule::command('backup:clean')->dailyAt('01:00')->onOneServer();
Schedule::command('backup:run')->dailyAt('01:30')->onOneServer();
Schedule::command('queue:prune-failed --hours=168')->daily()->onOneServer();
Schedule::command('horizon:snapshot')->everyFiveMinutes()->onOneServer();
