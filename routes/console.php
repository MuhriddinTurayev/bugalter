<?php

use Illuminate\Support\Facades\Schedule;

/*
| The autopilot and due submissions run hourly; reminders and key expiry checks once a day.
| Production needs one cron entry: * * * * * php artisan schedule:run
*/

Schedule::command('bugalter:autopilot')->hourly()->withoutOverlapping()->onOneServer();

// The tax cabinet visit comes before the reminders, so they already see what it prepared.
Schedule::command('bugalter:portal-check')->dailyAt('07:00')->timezone('Asia/Tashkent')->withoutOverlapping()->onOneServer();

Schedule::command('bugalter:daily')->dailyAt('08:00')->timezone('Asia/Tashkent')->withoutOverlapping()->onOneServer();
