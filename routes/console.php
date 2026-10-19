<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
| Scheduled tasks. Production runs `php artisan schedule:work` (or a cron calling schedule:run every minute).
| Backups run overnight Sydney time.
*/

// Backups: database (without G-NAF rows) + uploaded files. Problems are emailed to BACKUP_NOTIFY_EMAIL.
Schedule::command('backup:clean')->dailyAt('01:00')->timezone('Australia/Sydney')->onOneServer();
Schedule::command('backup:run')->dailyAt('01:30')->timezone('Australia/Sydney')->onOneServer()->withoutOverlapping();
Schedule::command('backup:monitor')->dailyAt('08:00')->timezone('Australia/Sydney')->onOneServer();

// Housekeeping
Schedule::command('payments:expire-abandoned')->hourly()->onOneServer();
Schedule::command('sanctum:prune-expired --hours=24')->daily()->onOneServer();
Schedule::command('queue:prune-failed --hours=336')->daily()->onOneServer();
Schedule::command('queue:prune-batches --hours=48')->daily()->onOneServer();
