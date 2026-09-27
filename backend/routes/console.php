<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('tedc:program-lifecycle')->hourly()->withoutOverlapping();
Schedule::command('tedc:session-reminders')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('tedc:dispatch-surveys')->dailyAt('08:00')->withoutOverlapping();
