<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Robot;
use App\Models\RobotTelemetrySample;
use App\Services\Rms\ServoCurrentEstimator;
use App\Services\Rms\ServoCurrentStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Optional, bounded recovery for telemetry received before persistence was installed. */
final class BackfillServoCurrent extends Command
{
    protected $signature = 'rms:servo-current:backfill
        {--robot= : Only process this numeric robot id}
        {--limit=10000 : Maximum samples per run (1-1000000)}
        {--chunk=250 : Database read batch size (1-1000)}
        {--apply : Persist missing snapshots; without this option only preview}';

    protected $description = 'Backfill missing servo-current estimates using profiles effective at the original torque time';

    public function handle(ServoCurrentStorage $storage): int
    {
        if (! Schema::hasColumn('robot_telemetry_samples', 'servo_current_estimate')) {
            $this->error('Jalankan php artisan migrate terlebih dahulu.');

            return self::FAILURE;
        }
        $robotId = $this->option('robot');
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false || $chunk === false || ($robotId !== null
            && filter_var($robotId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false)) {
            $this->error('robot harus ID positif; limit 1-1000000; chunk 1-1000.');

            return self::FAILURE;
        }
        $query = RobotTelemetrySample::query()->with('robot')->whereNull('servo_current_estimate')->whereHas('robot');
        if ($robotId !== null) {
            $query->where('robot_id', (int) $robotId);
        }
        $apply = (bool) $this->option('apply');
        $processed = 0;
        $saved = 0;
        $availableAxes = 0;
        foreach ($query->lazyById($chunk)->take($limit) as $sample) {
            $processed++;
            if (! $apply) {
                $attributes = $storage->attributes($sample->robot, $sample->getAttributes(), true);
            } else {
                $attributes = DB::transaction(function () use ($sample, $storage): ?array {
                    $locked = RobotTelemetrySample::query()->whereKey($sample->id)
                        ->whereNull('servo_current_estimate')->lockForUpdate()->first();
                    if ($locked === null) {
                        return null;
                    }
                    $robot = Robot::query()->findOrFail($locked->robot_id);
                    $attributes = $storage->attributes($robot, $locked->getAttributes(), true);
                    // Preserve source timestamps and avoid thousands of live broadcasts.
                    $locked->timestamps = false;
                    $locked->fill($attributes)->saveQuietly();

                    return $attributes;
                }, 3);
                if ($attributes !== null) {
                    $saved++;
                }
            }
            foreach (ServoCurrentEstimator::AXES as $axis) {
                if (($attributes['servo_current_'.strtolower($axis).'_arms'] ?? null) !== null) {
                    $availableAxes++;
                }
            }
        }
        $this->info(($apply ? 'APPLY' : 'PREVIEW').": processed={$processed}; saved={$saved}; available_axes={$availableAxes}");
        $this->line('Profil yang belum berlaku pada waktu sampel menghasilkan NULL beserta alasannya. Snapshot yang sudah ada tidak diubah.');
        if (! $apply) {
            $this->line('Tambahkan --apply untuk menyimpan hasil yang belum tercatat.');
        }

        return self::SUCCESS;
    }
}
