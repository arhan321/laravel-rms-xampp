<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\RobotAlarmSnapshot;
use App\Models\RobotTelemetrySample;
use App\Services\Rms\AlarmHistoryImporter;
use App\Services\Rms\ControllerStateIngestor;
use Illuminate\Console\Command;

/** Idempotent data migration for state/history already present in old raw JSON. */
final class BackfillCockpitEvidence extends Command
{
    protected $signature = 'rms:cockpit:backfill {--days=7 : Scan the last 1-30 days} {--robot= : Optional numeric robot ID}';

    protected $description = 'Import structured state and alarm history from existing RMS raw samples';

    public function handle(ControllerStateIngestor $states, AlarmHistoryImporter $history): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        $robot = $this->option('robot');
        $robotId = $robot === null ? null : filter_var($robot, FILTER_VALIDATE_INT);
        if ($days === false || $days < 1 || $days > 30
            || ($robot !== null && ($robotId === false || $robotId < 1))) {
            $this->error('--days must be 1-30 and --robot must be a positive ID.');

            return self::FAILURE;
        }

        $since = now()->subDays($days);
        $stateCount = 0;
        $historyCount = 0;
        $samples = RobotTelemetrySample::query()->where('sampled_at', '>=', $since)->whereNotNull('raw_payload');
        if ($robotId !== null) {
            $samples->where('robot_id', $robotId);
        }
        $samples->chunkById(500, function ($rows) use ($states, &$stateCount): void {
            foreach ($rows as $row) {
                $stateCount += $states->ingest($row) ? 1 : 0;
            }
        });

        $snapshots = RobotAlarmSnapshot::query()->where('sampled_at', '>=', $since)->whereNotNull('raw_payload');
        if ($robotId !== null) {
            $snapshots->where('robot_id', $robotId);
        }
        $snapshots->chunkById(200, function ($rows) use ($history, &$historyCount): void {
            foreach ($rows as $row) {
                $historyCount += $history->import($row)['imported'];
            }
        });

        $this->info("State samples processed: {$stateCount}; new history entries: {$historyCount}.");

        return self::SUCCESS;
    }
}
