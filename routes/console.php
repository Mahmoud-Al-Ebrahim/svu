<?php

use Illuminate\Support\Facades\Schedule;

// Run with `php artisan schedule:work` in development, or a cron entry calling `schedule:run` every minute.
Schedule::command('portal:close-finished')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('portal:session-reminders')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('portal:purge-uploads')->hourly();
Schedule::command('portal:purge-deleted-files')->dailyAt('03:00')->withoutOverlapping();
