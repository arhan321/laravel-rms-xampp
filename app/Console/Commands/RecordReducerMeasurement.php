<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Robot;
use App\Models\RobotReducerMeasurement;
use Illuminate\Console\Command;

/** Manually enter an externally verified inspection/OEM measurement: never estimate life. */
class RecordReducerMeasurement extends Command
{
    protected $signature = 'rms:reducer:record
        {robot : Robot code}
        {axis : S|L|U|R|B|T}
        {--value= : Condition reading from documented source}
        {--unit= : Verified condition measurement unit}
        {--remaining-hours= : OEM-provided remaining life hours (do not estimate)}
        {--lower= : OEM-provided lower limit}
        {--upper= : OEM-provided upper limit}
        {--source-type=oem_report : OEM source type}
        {--source-ref= : Unique reference of measurement evidence}
        {--verified-by= : Person who reviewed source evidence}
        {--verified : Explicit verification of units and OEM source}';
    protected $description = 'Record speed reducer measurement with source, verification and optional OEM life value';

    public function handle(): int
    {
        $axis = strtoupper((string)$this->argument('axis'));
        if (!in_array($axis, ['S','L','U','R','B','T'], true)) return $this->failWithMessage('Invalid axis.');
        $robot = Robot::query()->where('robot_code', $this->argument('robot'))->first();
        if (!$robot) return $this->failWithMessage('Robot does not exist.');
        if (!$this->option('verified') || blank($this->option('source-ref')) || blank($this->option('verified-by'))) {
            return $this->failWithMessage('Use --verified --source-ref --verified-by after validating OEM evidence.');
        }
        $num = [];
        foreach (['value','remaining-hours','lower','upper'] as $key) {
            $v = $this->option($key);
            if ($v === null) { $num[$key] = null; continue; }
            $parsed = filter_var($v, FILTER_VALIDATE_FLOAT);
            if ($parsed === false || !is_finite((float)$parsed)) return $this->failWithMessage('Invalid numeric --'.$key);
            $num[$key] = (float)$parsed;
        }
        if (($num['remaining-hours'] !== null && $num['remaining-hours'] < 0) ||
            ($num['value'] !== null && blank($this->option('unit'))) ||
            ($num['value'] === null && $num['remaining-hours'] === null) ||
            ($num['lower'] !== null && $num['upper'] !== null && $num['lower'] >= $num['upper'])) {
            return $this->failWithMessage('Invalid measurement/unit/lifetime/limits.');
        }
        RobotReducerMeasurement::query()->create([
            'robot_id'=>$robot->id,'axis'=>$axis,'measured_at'=>now(),
            'condition_value'=>$num['value'],'condition_unit'=>$this->option('unit'),
            'remaining_life_hours'=>$num['remaining-hours'],
            'lower_threshold'=>$num['lower'],'upper_threshold'=>$num['upper'],
            'source_type'=>$this->option('source-type'),
            'source_reference'=>$this->option('source-ref'),
            'is_verified'=>true,'verified_by'=>$this->option('verified-by'),
        ]);
        $this->info('Verified reducer record saved. No life estimation performed.');
        return self::SUCCESS;
    }
    private function failWithMessage(string $message): int
{
    $this->error($message);

    return self::FAILURE;
}
}
