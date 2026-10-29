<?php

declare(strict_types=1);

/*
 |--------------------------------------------------------------------------
 | RMS Yaskawa - API Routes (v1)
 |--------------------------------------------------------------------------
 | Laravel adds /api automatically via bootstrap/app.php; do NOT repeat /api.
 | API controllers: App\Http\Controllers\Api
 |
 | Prerequisites:
 | - Laravel Sanctum is installed/configured (php artisan install:api).
 | - Human auth + authorization Gates: rms.manage, rms.maintain.
 | - Collector authorization Gate: rms.ingest; Sanctum 'abilities' alias.
 | Gates intentionally DENY writes until explicitly implemented.
 | - Machine ingestion must require a dedicated bearer token, NOT SPA cookie.
 |
 | Health Intelligence additions:
 | - Rule-based robot health summary
 | - Statistical anomaly events
 | - Predictive maintenance forecasts
 | - Technician acknowledgement / review / feedback
 */

use App\Http\Controllers\Api\AlarmController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\ConnectionController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MaintenanceLogController;
use App\Http\Controllers\Api\MaintenancePlanController;
use App\Http\Controllers\Api\OperatingTimeController;
use App\Http\Controllers\Api\RecommendationController;
use App\Http\Controllers\Api\RobotController;
use App\Http\Controllers\Api\RobotRegistryController;
use App\Http\Controllers\Api\TelemetryController;

// RMS HEALTH INTELLIGENCE API
use App\Http\Controllers\Api\HealthIntelligenceController;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->as('api.v1.')
    ->middleware(['auth:sanctum'])
    ->group(function (): void {
        // Current authenticated user
        Route::get('/me', fn (Request $request) => response()->json([
            'data' => $request->user(),
        ]))->name('me');

        // Overview and master-data reads
        Route::get('/dashboard/overview', [DashboardController::class, 'overview'])
            ->name('dashboard.overview');
        Route::get('/controllers', [RobotRegistryController::class, 'controllers'])
            ->name('controllers.index');
        Route::get('/robots', [RobotController::class, 'index'])
            ->name('robots.index');
        Route::get('/robots/{robot}', [RobotController::class, 'show'])
            ->whereNumber('robot')->name('robots.show');

        // Robot telemetry, operating time, alarms, connection and health
        Route::prefix('robots/{robot}')
            ->where(['robot' => '[0-9]+'])
            ->name('robots.')
            ->group(function (): void {
                Route::get('/telemetry/latest', [TelemetryController::class, 'latest'])
                    ->name('telemetry.latest');
                Route::get('/telemetry', [TelemetryController::class, 'index'])
                    ->name('telemetry.index');

                Route::get('/operating-times/latest', [OperatingTimeController::class, 'latest'])
                    ->name('operating-times.latest');
                Route::get('/operating-times', [OperatingTimeController::class, 'index'])
                    ->name('operating-times.index');

                Route::get('/alarms/snapshots/latest', [AlarmController::class, 'latestSnapshot'])
                    ->name('alarms.snapshots.latest');
                Route::get('/alarms/snapshots', [AlarmController::class, 'snapshots'])
                    ->name('alarms.snapshots.index');

                Route::get('/connections', [ConnectionController::class, 'index'])
                    ->name('connections.index');
                Route::get('/health/latest', [HealthController::class, 'latest'])
                    ->name('health.latest');
                Route::get('/health', [HealthController::class, 'index'])
                    ->name('health.index');
            });

        // Global reads
        Route::get('/alarms/events', [AlarmController::class, 'events'])
            ->name('alarms.events.index');
        Route::get('/alarms/history', [AlarmController::class, 'history'])
            ->name('alarms.history.index');
        Route::get('/maintenance/plans', [MaintenancePlanController::class, 'index'])
            ->name('maintenance.plans.index');
        Route::get('/maintenance/plans/{maintenancePlan}', [MaintenancePlanController::class, 'show'])
            ->whereNumber('maintenancePlan')->name('maintenance.plans.show');
        Route::get('/maintenance/logs', [MaintenanceLogController::class, 'index'])
            ->name('maintenance.logs.index');
        Route::get('/maintenance/logs/{maintenanceLog}', [MaintenanceLogController::class, 'show'])
            ->whereNumber('maintenanceLog')->name('maintenance.logs.show');
        Route::get('/recommendations', [RecommendationController::class, 'index'])
            ->name('recommendations.index');
        Route::get('/recommendations/{recommendation}', [RecommendationController::class, 'show'])
            ->whereNumber('recommendation')->name('recommendations.show');
        Route::get('/analytics/data-quality', [AnalyticsController::class, 'dataQuality'])
            ->name('analytics.data-quality');

        // ====================================================
        // RMS HEALTH INTELLIGENCE - FASE 1 / 2 / 3 READ API
        // ====================================================

        /*
         * FASE 1 - RULE-BASED HEALTH
         * GET /api/v1/health-intelligence/summary
         * GET /api/v1/health-intelligence/robots/{robot}
         *
         * FASE 2 - ANOMALY DETECTION
         * GET /api/v1/anomaly/events
         *
         * FASE 3 - PREDICTIVE MAINTENANCE
         * GET /api/v1/predictive/forecasts
         */

        Route::get('/health-intelligence/summary', [
            HealthIntelligenceController::class,
            'summary'
        ])->name('health-intelligence.summary');

        Route::get('/health-intelligence/robots/{robot}', [
            HealthIntelligenceController::class,
            'robot'
        ])
        ->whereNumber('robot')
        ->name('health-intelligence.robot');

        Route::get('/anomaly/events', [
            HealthIntelligenceController::class,
            'anomalies'
        ])->name('anomaly.events');

        Route::get('/predictive/forecasts', [
            HealthIntelligenceController::class,
            'predictions'
        ])->name('predictive.forecasts');

        // Read-only controller/robot registry until admin authorization is set.
        // All writes below are deliberately fail-closed using Gate middleware.

        // Data collector: dedicate a machine account and restricted bearer token.
        // The 'abilities' check alone is NOT sufficient for Sanctum SPA sessions.
        Route::prefix('collector')
            ->name('collector.')
            ->middleware(['abilities:rms:ingest', 'can:rms.ingest'])
            ->group(function (): void {
                Route::post('/telemetry', [TelemetryController::class, 'store'])
                    ->name('telemetry.store');
                Route::post('/operating-times', [OperatingTimeController::class, 'store'])
                    ->name('operating-times.store');
                Route::post('/alarms/snapshots', [AlarmController::class, 'storeSnapshot'])
                    ->name('alarms.snapshots.store');
                Route::post('/connections', [ConnectionController::class, 'store'])
                    ->name('connections.store');
            });

        // Registry management (requires a separately defined admin permission).
        Route::prefix('admin')
            ->name('admin.')
            ->middleware('can:rms.manage')
            ->group(function (): void {
                Route::post('/controllers', [RobotRegistryController::class, 'storeController'])
                    ->name('controllers.store');
                Route::patch('/controllers/{controller}', [RobotRegistryController::class, 'updateController'])
                    ->whereNumber('controller')->name('controllers.update');
                Route::post('/robots', [RobotRegistryController::class, 'storeRobot'])
                    ->name('robots.store');
                Route::patch('/robots/{robot}', [RobotRegistryController::class, 'updateRobot'])
                    ->whereNumber('robot')->name('robots.update');
            });

        // Maintenance staff actions (authorization Gate must be implemented first).
        Route::middleware('can:rms.maintain')
            ->group(function (): void {
                Route::post('/maintenance/plans', [MaintenancePlanController::class, 'store'])
                    ->name('maintenance.plans.store');
                Route::patch('/maintenance/plans/{maintenancePlan}', [MaintenancePlanController::class, 'update'])
                    ->whereNumber('maintenancePlan')->name('maintenance.plans.update');
                Route::patch('/maintenance/plans/{maintenancePlan}/deactivate', [MaintenancePlanController::class, 'deactivate'])
                    ->whereNumber('maintenancePlan')->name('maintenance.plans.deactivate');
                Route::post('/maintenance/logs', [MaintenanceLogController::class, 'store'])
                    ->name('maintenance.logs.store');
                Route::patch('/maintenance/logs/{maintenanceLog}/verify', [MaintenanceLogController::class, 'verify'])
                    ->whereNumber('maintenanceLog')->name('maintenance.logs.verify');
                Route::patch('/recommendations/{recommendation}/acknowledge', [RecommendationController::class, 'acknowledge'])
                    ->whereNumber('recommendation')->name('recommendations.acknowledge');
                Route::patch('/recommendations/{recommendation}/resolve', [RecommendationController::class, 'resolve'])
                    ->whereNumber('recommendation')->name('recommendations.resolve');

                // ====================================================
                // FASE 2 - ANOMALY TECHNICIAN ACTIONS
                // ====================================================

                // Technician acknowledges an open anomaly event.
                Route::patch('/anomaly/events/{event}/acknowledge', [
                    HealthIntelligenceController::class,
                    'acknowledge'
                ])
                ->whereNumber('event')
                ->name('anomaly.acknowledge');

                // Technician reviews anomaly and labels it confirmed,
                // false_positive, or inconclusive using a verified maintenance log.
                Route::patch('/anomaly/events/{event}/review', [
                    HealthIntelligenceController::class,
                    'review'
                ])
                ->whereNumber('event')
                ->name('anomaly.review');

                // ====================================================
                // FASE 3 - PREDICTIVE TECHNICIAN FEEDBACK
                // ====================================================

                // Technician feedback is later used as retraining evidence.
                Route::patch('/predictive/forecasts/{prediction}/feedback', [
                    HealthIntelligenceController::class,
                    'predictionFeedback'
                ])
                ->whereNumber('prediction')
                ->name('predictive.feedback');
            });
    });
