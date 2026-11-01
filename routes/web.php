<?php

/*
|--------------------------------------------------------------------------
| RMS YASKAWA - WEB ROUTES
|--------------------------------------------------------------------------
|
| Project  : Robot Management System (RMS)
| Laravel  : Laravel 13
| PHP      : PHP 8.5
| Database : PostgreSQL 16
| Server   : Nginx Docker - Port 8080
|
| Dashboard tanpa login
|
| Features:
| - Dashboard Overview
| - Robot Monitoring
| - Robot Detail
| - Robot Cockpit
| - Torque & Axis Health Monitoring
| - Threshold Visualization
| - Robot Log History
| - Multi-Robot & Shift Filter
| - CSV Export
| - Alarm Monitoring
| - Preventive Maintenance
| - Maintenance Recommendation
| - Data Analytics
| - Connection Monitoring
| - Notification Center
| - Hardware Insights
| - Feature Status
| - Robot Health Intelligence
| - Rule-Based Health Assessment
| - Statistical Anomaly Detection
| - Predictive Maintenance
| - Remaining Useful Life / Condition Forecast
|
|--------------------------------------------------------------------------
*/

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Web\AlarmController;
// ============================================================
// RMS WEB CONTROLLERS
// ============================================================

use App\Http\Controllers\Web\RobotController;
use App\Http\Controllers\Web\CockpitController;
use App\Http\Controllers\Web\FeatureController;
use App\Http\Controllers\Web\RmsLiveController;
use App\Http\Controllers\Web\HardwareController;
use App\Http\Controllers\Web\RobotLogController;
use App\Http\Controllers\Web\AnalyticsController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\ConnectionController;
use App\Http\Controllers\Web\RobotGroupController;
use App\Http\Controllers\Web\CockpitLiveController;
use App\Http\Controllers\Web\MaintenanceController;
use App\Http\Controllers\Web\NotificationController;
use App\Http\Controllers\Web\ServoCurrentController;
use App\Http\Controllers\Web\RobotIdentityController;
// ============================================================
// RMS HEALTH INTELLIGENCE CONTROLLER
// ============================================================

use App\Http\Controllers\Web\RecommendationController;
use App\Http\Controllers\Web\RmsConfigurationController;
use App\Http\Controllers\Web\HealthIntelligenceController;
use App\Http\Controllers\Web\AlarmCountermeasureController;

// ============================================================
// 1. HOME
// ============================================================

Route::redirect('/', '/rms')
    ->name('home');

// ============================================================
// 2. RMS DASHBOARD - NO LOGIN
// ============================================================

Route::prefix('rms')
    ->name('rms.')
    ->group(function (): void {

        // ====================================================
        // DASHBOARD OVERVIEW
        // ====================================================

        Route::get('/', [
            DashboardController::class,
            'index',
        ])->name('overview');

        // ====================================================
        // ROBOT MONITORING
        // ====================================================

        Route::get('/robot-groups', [RobotGroupController::class, 'index'])->name('robot-groups.index');
        Route::get('/robot-groups/create', [RobotGroupController::class, 'create'])->name('robot-groups.create');
        Route::post('/robot-groups', [RobotGroupController::class, 'store'])->middleware('throttle:20,1')->name('robot-groups.store');
        Route::get('/robot-groups/{robotGroup}/edit', [RobotGroupController::class, 'edit'])->whereNumber('robotGroup')->name('robot-groups.edit');
        Route::put('/robot-groups/{robotGroup}', [RobotGroupController::class, 'update'])->whereNumber('robotGroup')->middleware('throttle:20,1')->name('robot-groups.update');
        Route::delete('/robot-groups/{robotGroup}', [RobotGroupController::class, 'destroy'])->whereNumber('robotGroup')->middleware('throttle:10,1')->name('robot-groups.destroy');

        Route::get('/robots/create', [
            RobotController::class,
            'create',
        ])->name('robots.create');

        Route::post('/robots/probe', [
            RobotController::class,
            'probe',
        ])->middleware('throttle:20,1')->name('robots.probe');

        Route::post('/robots', [
            RobotController::class,
            'store',
        ])->middleware('throttle:10,1')->name('robots.store');

        Route::get('/robots', [
            RobotController::class,
            'index',
        ])->name('robots.index');

        Route::delete('/robots/{robot}', [
            RobotController::class,
            'destroy',
        ])->middleware('throttle:10,1')->whereNumber('robot')->name('robots.destroy');

        // ====================================================
        // ROBOT COCKPIT - YASKAWA EQUIPMENT HEALTH
        // ====================================================

        /*
         * Robot Cockpit includes:
         *
         * - Motor Torque Monitoring
         * - Axis S, L, U, R, B, T
         * - Torque History Graph
         * - Measurement & Variation
         * - Threshold Warning / Critical
         * - Axis Health Indicators
         * - Speed Reducer Condition
         * - Maintenance Health Information
         *
         * Read-only monitoring interface.
         */

        Route::get('/robots/{robot}/cockpit', [
            CockpitController::class,
            'show',
        ])
            ->whereNumber('robot')
            ->name('robots.cockpit');

        // Trusted-LAN configuration for evidence-backed threshold/reducer data.
        Route::get('/robots/{robot}/servo-current', [ServoCurrentController::class, 'edit'])
            ->whereNumber('robot')->name('robots.servo-current');
        Route::put('/robots/{robot}/servo-current', [ServoCurrentController::class, 'update'])
            ->whereNumber('robot')->middleware('throttle:20,1')->name('robots.servo-current.update');
        Route::get('/robots/{robot}/servo-current/live', [ServoCurrentController::class, 'live'])
            ->whereNumber('robot')->middleware('throttle:120,1')->name('robots.servo-current.live');

        Route::get('/robots/{robot}/configuration', [
            RmsConfigurationController::class,
            'edit',
        ])->whereNumber('robot')->name('robots.configuration');

        Route::put('/robots/{robot}/configuration/threshold', [
            RmsConfigurationController::class,
            'saveThreshold',
        ])->whereNumber('robot')->middleware('throttle:30,1')->name('robots.configuration.threshold');

        Route::post('/robots/{robot}/configuration/reducer', [
            RmsConfigurationController::class,
            'storeReducerMeasurement',
        ])->whereNumber('robot')->middleware('throttle:20,1')->name('robots.configuration.reducer');

        // ====================================================
        // ROBOT COCKPIT LIVE - MERGED FROM rms_cockpit_live.php
        // Read-only JSON, same-origin, no additional login.
        // This route is already inside the Laravel "web" middleware group.
        // ====================================================
        Route::get('/robots/{robot}/cockpit/live', CockpitLiveController::class)
            ->whereNumber('robot')
            ->middleware('throttle:120,1')
            ->name('robots.cockpit.live');

        // ====================================================
        // ROBOT DETAIL
        // ====================================================

        Route::get('/robots/{robot}/identity', [RobotIdentityController::class, 'edit'])
            ->whereNumber('robot')->name('robots.identity');
        Route::put('/robots/{robot}/identity', [RobotIdentityController::class, 'update'])
            ->whereNumber('robot')->middleware('throttle:20,1')->name('robots.identity.update');
        Route::put('/robots/{robot}/home-position', [RobotIdentityController::class, 'saveHome'])
            ->whereNumber('robot')->middleware('throttle:20,1')->name('robots.home.update');

        Route::get('/robots/{robot}', [
            RobotController::class,
            'show',
        ])
            ->whereNumber('robot')
            ->name('robots.show');

        // ====================================================
        // ROBOT LOG HISTORY
        // ====================================================

        /*
         * Features:
         *
         * - All Robot Logs
         * - Multi-Robot Selection
         * - Robot ID Filtering
         * - Date Range Filtering
         * - Shift Filtering
         * - Custom Time Range
         * - Telemetry History
         * - Alarm History
         * - Connection History
         * - Maintenance History
         */

        Route::get('/logs', [
            RobotLogController::class,
            'index',
        ])->name('logs.index');

        // ====================================================
        // ROBOT LOG CSV EXPORT
        // ====================================================

        Route::get('/logs/export', [
            RobotLogController::class,
            'export',
        ])
            ->middleware('throttle:10,1')
            ->name('logs.export');

        // ====================================================
        // ALARM MONITORING
        // ====================================================

        Route::get('/alarms', [
            AlarmController::class,
            'index',
        ])->name('alarms.index');

        Route::prefix('alarms/{kind}/{record}/countermeasures')
            ->where(['kind' => 'history|event|snapshot', 'record' => '[0-9]+'])
            ->name('alarms.countermeasures.')
            ->group(function (): void {
                Route::get('/', [AlarmCountermeasureController::class, 'show'])->name('show');
                Route::post('/', [AlarmCountermeasureController::class, 'store'])
                    ->middleware('throttle:20,1')->name('store');
                Route::get('/{countermeasure}/edit', [AlarmCountermeasureController::class, 'edit'])
                    ->whereNumber('countermeasure')->name('edit');
                Route::put('/{countermeasure}', [AlarmCountermeasureController::class, 'update'])
                    ->whereNumber('countermeasure')->middleware('throttle:20,1')->name('update');
            });

        // ====================================================
        // PREVENTIVE MAINTENANCE
        // ====================================================

        Route::get('/maintenance', [
            MaintenanceController::class,
            'index',
        ])->name('maintenance.index');

        // Operator-friendly preventive maintenance (NO login / NO verification).
        // CSRF is applied by Laravel web middleware; writes are rate-limited.
        // Controller additionally disables guest writes outside trusted environments.
        Route::post('/maintenance/plans', [
            MaintenanceController::class, 'storePlan',
        ])->middleware('throttle:20,1')->name('maintenance.store');

        Route::patch('/maintenance/plans/{maintenancePlan}', [
            MaintenanceController::class, 'updatePlan',
        ])->whereNumber('maintenancePlan')
            ->middleware('throttle:20,1')->name('maintenance.update');

        Route::post('/maintenance/plans/{maintenancePlan}/complete', [
            MaintenanceController::class, 'completeWork',
        ])->whereNumber('maintenancePlan')
            ->middleware('throttle:20,1')->name('maintenance.complete');

        // ====================================================
        // MAINTENANCE RECOMMENDATION
        // ====================================================

        Route::get('/recommendations', [
            RecommendationController::class,
            'index',
        ])->name('recommendations.index');

        // ====================================================
        // MAINTENANCE RECOMMENDATION - OPERATOR ACTIONS
        // No login / no approval; Laravel CSRF + throttling + config gate.
        // Existing GET /recommendations and all previous routes remain.
        // ====================================================
        Route::post('/recommendations/manual', [
            RecommendationController::class, 'storeManual',
        ])->middleware('throttle:20,1')->name('recommendations.manual.store');

        Route::post('/recommendations/{recommendation}/acknowledge', [
            RecommendationController::class, 'acknowledge',
        ])->whereNumber('recommendation')
            ->middleware('throttle:20,1')->name('recommendations.acknowledge');

        Route::post('/recommendations/{recommendation}/action', [
            RecommendationController::class, 'recordAction',
        ])->whereNumber('recommendation')
            ->middleware('throttle:20,1')->name('recommendations.action');

        Route::post('/recommendations/{recommendation}/dismiss', [
            RecommendationController::class, 'dismiss',
        ])->whereNumber('recommendation')
            ->middleware('throttle:20,1')->name('recommendations.dismiss');

        // ====================================================
        // DATA ANALYTICS
        // ====================================================

        Route::get('/analytics', [
            AnalyticsController::class,
            'index',
        ])->name('analytics.index');

        // ====================================================
        // CONNECTION MONITORING
        // ====================================================

        Route::get('/connections', [
            ConnectionController::class,
            'index',
        ])->name('connections.index');

        // ====================================================
        // NOTIFICATION CENTER
        // ====================================================

        Route::get('/notifications', [
            NotificationController::class,
            'index',
        ])->name('notifications.index');

        // ====================================================
        // HARDWARE INSIGHTS
        // ====================================================

        Route::get('/hardware', [
            HardwareController::class,
            'index',
        ])->name('hardware.index');

        // ====================================================
        // FEATURE STATUS
        // ====================================================

        Route::get('/features', [
            FeatureController::class,
            'index',
        ])->name('features.index');

        // ====================================================
        // ROBOT HEALTH INTELLIGENCE
        // ====================================================

        /*
         * FASE 1 - RULE-BASED HEALTH
         *
         * - Healthy / Warning / Critical / Unknown
         * - NG per Axis S, L, U, R, B, T
         * - Alarm Severity
         * - Preventive Maintenance by Operating Hours
         * - Lifetime to Service
         * - Maintenance Recommendation
         * - Robot Health Summary
         *
         * FASE 2 - ANOMALY DETECTION
         *
         * - Baseline per Robot / Axis / Job
         * - Statistical Anomaly
         * - Anomaly Score
         * - Baseline vs Actual
         * - Technician Acknowledgement
         * - Confirmed / False Positive Feedback
         *
         * FASE 3 - PREDICTIVE MAINTENANCE
         *
         * - Remaining Useful Life / Condition Forecast
         * - Estimated Time to Threshold
         * - Root Cause Candidate / Supporting Evidence
         * - Model Versioning
         * - Model Accuracy / Evaluation
         * - Retraining using Verified Technician Inspection
         *
         * IMPORTANT:
         * Predictive values are experimental RMS calculations and are NOT
         * equivalent to proprietary Yaskawa Cockpit algorithms.
         */

        // Health Intelligence Overview - all registered robots
        Route::get('/health', [
            HealthIntelligenceController::class,
            'index',
        ])->name('health.index');

        // Health Intelligence Detail - one robot
        Route::get('/health/{robot}', [
            HealthIntelligenceController::class,
            'show',
        ])
            ->whereNumber('robot')
            ->name('health.show');

        // ====================================================
        // RMS LIVE SNAPSHOT (read-only, same-origin JSON)
        // ====================================================
        Route::get('/live/snapshot', [
            RmsLiveController::class,
            'snapshot',
        ])->middleware('throttle:120,1')->name('live.snapshot');

    });

// ============================================================
// END OF RMS WEB ROUTES
// ============================================================
