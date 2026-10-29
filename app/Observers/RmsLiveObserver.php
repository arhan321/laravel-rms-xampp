<?php

declare(strict_types=1);

namespace App\Observers;

use App\Events\RmsRobotChanged;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Attach ONLY to already-existing RMS readout models.
 * A Reverb outage must not invalidate or fail a successfully ingested sample.
 * SQL inserts made outside Eloquent do not trigger observers: use the API/ORM.
 */
final class RmsLiveObserver implements ShouldHandleEventsAfterCommit
{
    public function created(Model $model): void { $this->notify($model); }
    public function updated(Model $model): void { $this->notify($model); }

    private function notify(Model $model): void
    {
        if (!config('rms_live.enabled', false)) return;
        $robotId = (int) $model->getAttribute('robot_id');
        if ($robotId < 1) return;

        // Publish each committed ORM change. Suppressing a change for one
        // second can hide a fresh telemetry sample behind another model's
        // notification and leave the Cockpit waiting for its fallback poll.
        try {
            event(new RmsRobotChanged($robotId, class_basename($model)));
        } catch (Throwable $e) {
            Log::warning('RMS live notification failed; telemetry remains persisted.', [
                'robot_id' => $robotId, 'exception' => get_class($e),
            ]);
        }
    }
}
