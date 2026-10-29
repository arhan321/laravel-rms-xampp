<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire',function(){ $this->comment(Inspiring::quote()); })
    ->purpose('Display an inspiring quote');

// Reuses the existing recommendation generator opt-in.
if(config('rms_recommendations.auto_enabled',false)){
    Schedule::command('rms:recommendations-generate')
        ->everyTwoMinutes()->withoutOverlapping();
}

// Start this ONLY after reviewing thresholds and collector readiness.
// Requires Laravel schedule:run to actually execute; code alone is not a cron service.
if(config('rms_evidence.auto_evaluate',false)){
    Schedule::command('rms:threshold:evaluate')->everyMinute()->withoutOverlapping();
    Schedule::command('rms:health-evaluate')->everyMinute()->withoutOverlapping();
    Schedule::command('rms:anomaly-evaluate')->everyTwoMinutes()->withoutOverlapping();
}
