<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Time-range indexes across all robots, for PostgreSQL and SQL Server. */
return new class extends Migration
{
    private const INDEXES = [
        'rms_telemetry_time_all_idx' => ['robot_telemetry_samples', 'sampled_at'],
        'rms_operating_time_all_idx' => ['robot_operating_time_samples', 'sampled_at'],
        'rms_alarm_snap_time_all_idx' => ['robot_alarm_snapshots', 'sampled_at'],
        'rms_alarm_event_time_all_idx' => ['robot_alarm_events', 'started_at'],
        'rms_connection_time_all_idx' => ['robot_connection_events', 'occurred_at'],
        'rms_health_time_all_idx' => ['robot_health_assessments', 'evaluated_at'],
        'rms_rec_time_all_idx' => ['maintenance_recommendations', 'generated_at'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => [$table, $time]) {
            if (! Schema::hasIndex($table, $name)) {
                Schema::table($table, static function (Blueprint $blueprint) use ($name, $time): void {
                    $blueprint->index([$time, 'id'], $name);
                });
            }
        }

        if (DB::connection()->getDriverName() === 'sqlsrv') {
            // T-SQL indexes require a column rather than a COALESCE expression.
            if (! Schema::hasColumn('maintenance_logs', 'rms_log_at')) {
                Schema::table('maintenance_logs', static function (Blueprint $table): void {
                    $table->computed('rms_log_at', 'COALESCE(completed_at, started_at, created_at)')->persisted();
                });
            }
            if (! Schema::hasIndex('maintenance_logs', 'rms_maintenance_log_time_all_idx')) {
                Schema::table('maintenance_logs', static function (Blueprint $table): void {
                    $table->index(['rms_log_at', 'id'], 'rms_maintenance_log_time_all_idx');
                });
            }
        } else {
            DB::statement('CREATE INDEX IF NOT EXISTS rms_maintenance_log_time_all_idx ON maintenance_logs ((COALESCE(completed_at, started_at, created_at)) DESC, id DESC)');
        }
    }

    public function down(): void
    {
        $tables = array_map(static fn (array $entry): string => $entry[0], self::INDEXES);
        $tables['rms_maintenance_log_time_all_idx'] = 'maintenance_logs';
        foreach ($tables as $name => $table) {
            if (Schema::hasIndex($table, $name)) {
                Schema::table($table, static function (Blueprint $blueprint) use ($name): void {
                    $blueprint->dropIndex($name);
                });
            }
        }
        if (DB::connection()->getDriverName() === 'sqlsrv' && Schema::hasColumn('maintenance_logs', 'rms_log_at')) {
            Schema::table('maintenance_logs', static function (Blueprint $table): void {
                $table->dropColumn('rms_log_at');
            });
        }
    }
};
