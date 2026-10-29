<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Robot;
use App\Models\RobotTelemetrySample;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Explicit DEMO_ robots only; never replays or writes to controller hardware. */
final class RmsLiveDemo extends Command
{
    protected $signature = 'rms:live-demo {robot_code : Only DEMO_ robot_code} {--count=10} {--interval=2}';
    protected $description = 'Write clearly labeled DEMO-only telemetry through the real Eloquent/observer pipeline';

    public function handle(): int
    {
        if (!app()->environment(['local','testing']) || !config('rms_live.allow_demo_writer', false)) {
            $this->error('Demo writer disabled. Requires APP_ENV=local/testing and RMS_LIVE_ALLOW_DEMO_WRITER=true.');
            return self::FAILURE;
        }
        $code = (string) $this->argument('robot_code');
        if (!str_starts_with($code, 'DEMO_')) {
            $this->error('Refusing non-DEMO_ robot code.');
            return self::FAILURE;
        }
        $robot = Robot::query()->where('robot_code', $code)->first();
        if (!$robot) { $this->error('DEMO_ robot not registered.'); return self::FAILURE; }
        $count = (int) $this->option('count');
        $interval = (int) $this->option('interval');
        if ($count < 1 || $count > 120 || $interval < 1 || $interval > 10) {
            $this->error('--count must be 1..120 and --interval must be 1..10 seconds.');
            return self::FAILURE;
        }
        $sessionId = (string) Str::uuid();
        $axes = ['S','L','U','R','B','T'];
        for ($i=0; $i<$count; $i++) {
            $torques = [];
            $pulses = [];
            foreach ($axes as $k=>$axis) {
                $torques[$axis] = round(9 + $k * 2.3 + sin(($i + $k) * 0.35) * 1.7, 4);
                $pulses[$axis] = 100000 + 9000*$k + 120*$i;
            }
            RobotTelemetrySample::query()->create([
                'robot_id'=>$robot->id,'collector_session_id'=>$sessionId,'sequence'=>$i,
                'sampled_at'=>now(), 'position_ok'=>true,'position_pulses'=>$pulses,
                'torque_ok'=>true, 'torque_unit'=>'sdk_raw','torque_axes'=>$torques,
                'raw_payload'=>['source'=>'explicit_demo_only','not_robot_measurement'=>true],
            ]);
            $this->line('DEMO sample '.($i+1).'/'.$count.' sent to database.');
            if ($i < $count-1) sleep($interval);
        }
        return self::SUCCESS;
    }
}
