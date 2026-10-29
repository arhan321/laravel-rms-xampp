<?php

declare(strict_types=1);

namespace App\Providers;

use App\Database\RmsSqlServerConnection;
use Illuminate\Database\Connection;
use App\Models\MaintenanceRecommendation;
use App\Models\RobotAlarmEvent;
use App\Models\RobotAlarmSnapshot;
use App\Models\RobotConnectionEvent;
use App\Models\RobotHealthAssessment;
use App\Models\RobotOperatingTimeSample;
use App\Models\RobotReducerMeasurement;
use App\Models\RobotTelemetrySample;
use App\Models\RobotThresholdEvent;
use App\Models\RobotThresholdRule;
use App\Observers\RmsLiveObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Connection::resolverFor('sqlsrv', static function ($pdo, $database, $prefix, $config) {
            return new RmsSqlServerConnection($pdo, $database, $prefix, $config);
        });
    }

    public function boot(): void
    {
        // Existing observer broadcasts a small invalidation signal via Reverb
        // when RMS_LIVE_ENABLED=true. With Reverb offline the browser falls
        // back to the read-only JSON endpoint every 5 seconds.
        foreach ([
            RobotTelemetrySample::class,
            RobotOperatingTimeSample::class,
            RobotAlarmSnapshot::class,
            RobotAlarmEvent::class,
            RobotConnectionEvent::class,
            RobotThresholdEvent::class,
            RobotThresholdRule::class,
            RobotReducerMeasurement::class,
            RobotHealthAssessment::class,
            MaintenanceRecommendation::class,
        ] as $model) {
            $model::observe(RmsLiveObserver::class);
        }
        // \Illuminate\Support\Facades\URL::forceScheme('https');
    }
}

