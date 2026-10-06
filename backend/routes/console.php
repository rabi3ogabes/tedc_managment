<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('tedc:program-lifecycle')->hourly()->withoutOverlapping();
Schedule::command('tedc:group-lifecycle')->hourly()->withoutOverlapping();
Schedule::command('tedc:operations-hourly')->hourly()->withoutOverlapping();
Schedule::command('tedc:caliper-flush')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('tedc:evaluations-hourly')->hourly()->withoutOverlapping();
Schedule::command('tedc:seats-release')->hourly()->withoutOverlapping();
Schedule::command('tedc:career-daily')->dailyAt('01:00')->withoutOverlapping();
Schedule::command('tedc:needs-daily')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('tedc:needs-cycles')->dailyAt('06:30')->withoutOverlapping();
Schedule::command('tedc:plan-deviations')->dailyAt('07:00')->withoutOverlapping();
Schedule::command('tedc:session-reminders')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('tedc:dispatch-surveys')->dailyAt('08:00')->withoutOverlapping();
Schedule::command('tedc:attendance-nudges')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('tedc:open-surveys')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('tedc:trainer-certificates')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('tedc:self-heal')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('tedc:course-nudges')->dailyAt('10:00')->withoutOverlapping();
Schedule::command('tedc:scenario-advance')->dailyAt('00:10')->withoutOverlapping();
Schedule::command('tedc:deliver-notifications')->everyMinute()->withoutOverlapping();
Schedule::command('tedc:announcements-tick')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('tedc:ministry-push')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('tedc:stats-refresh')->everyTenMinutes()->withoutOverlapping();
Schedule::command('tedc:kpi-collect')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('tedc:reports-run')->everyMinute()->withoutOverlapping();
