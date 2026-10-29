<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Robot;
use App\Models\RobotThresholdRule;
use Illuminate\Console\Command;

/** No public dashboard writer: threshold change requires authorized server console access. */
class ConfigureRmsThreshold extends Command
{
    protected $signature = 'rms:threshold:set
        {robot : Robot code}
        {axis : S|L|U|R|B|T}
        {warning : Approved magnitude threshold}
        {critical : Approved magnitude threshold}
        {--source= : Source document/change ticket}
        {--approved-by= : Named maintenance approver}
        {--confirm-unit : Operator confirms sdk_raw magnitude/unit was verified}
        {--hysteresis=5 : Clear percentage 0-30}
        {--enable : Enable rule after approval}
        {--disable : Disable existing rule}';
    protected $description = 'Configure per-axis SDK raw torque threshold through authorized CLI';

    public function handle(): int
    {
        $axis = strtoupper((string)$this->argument('axis'));
        if (!in_array($axis, ['S','L','U','R','B','T'], true)) { $this->error('Invalid axis.'); return self::FAILURE; }
        $warning = filter_var($this->argument('warning'), FILTER_VALIDATE_FLOAT);
        $critical = filter_var($this->argument('critical'), FILTER_VALIDATE_FLOAT);
        $hys = filter_var($this->option('hysteresis'), FILTER_VALIDATE_FLOAT);
        if ($warning === false || $critical === false || $hys === false ||
            $warning <= 0 || $critical <= $warning || $hys < 0 || $hys > 30 ||
            !is_finite($warning) || !is_finite($critical)) {
            $this->error('Require 0 < warning < critical and hysteresis between 0..30.');
            return self::FAILURE;
        }
        $robot = Robot::query()->where('robot_code', $this->argument('robot'))->first();
        if (!$robot) { $this->error('Robot code not registered.'); return self::FAILURE; }
        if ($this->option('enable') && $this->option('disable')) { $this->error('Choose enable or disable.'); return self::FAILURE; }
        if ($this->option('enable') && (!$this->option('confirm-unit') ||
            blank($this->option('source')) || blank($this->option('approved-by')))) {
            $this->error('Cannot enable: --confirm-unit, --source, --approved-by are mandatory.');
            return self::FAILURE;
        }
        // Default safety: saved rules remain disabled until explicitly enabled.
        $enabled = (bool)$this->option('enable');
        $rule = RobotThresholdRule::query()->firstOrNew([
            'robot_id'=>$robot->id, 'axis'=>$axis, 'metric'=>'torque_magnitude',
        ]);
        // Changing limits invalidates former approval AND active-event interpretation.
        if ($rule->exists && $rule->events()->where('status','open')->exists()) {
            $this->error('Rule has an open event; resolve/review the event before changing limits.');
            return self::FAILURE;
        }
        $rule->fill([
            'unit'=>'sdk_raw','warning_magnitude'=>$warning,'critical_magnitude'=>$critical,
            'clear_hysteresis_percent'=>$hys,'enabled'=>$enabled,
            'unit_verified'=>$enabled && $this->option('confirm-unit'),
            'source_reference'=>$enabled ? $this->option('source') : null,
            'approved_by'=>$enabled ? $this->option('approved-by') : null,
            'approved_at'=>$enabled ? now() : null,
            'last_evaluated_sample_id'=>null,
        ]);
        $rule->save();
        $this->info("Saved {$robot->robot_code}/{$axis}, enabled=".($enabled?'yes':'no'));
        return self::SUCCESS;
    }
}
