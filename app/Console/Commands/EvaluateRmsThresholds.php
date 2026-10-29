<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\RobotThresholdRule;
use App\Services\Rms\ThresholdEvaluator;
use Illuminate\Console\Command;

class EvaluateRmsThresholds extends Command
{
    protected $signature = 'rms:threshold:evaluate {--robot= : Robot code (optional)}';
    protected $description = 'Evaluate approved RMS torque thresholds against latest PostgreSQL telemetry';

    public function handle(ThresholdEvaluator $evaluator): int
    {
        $rules = RobotThresholdRule::query()->where('enabled', true)
            ->with('robot')
            ->when($this->option('robot'), fn ($q) => $q->whereHas('robot',
                fn ($r) => $r->where('robot_code', $this->option('robot'))))
            ->get();
        foreach ($rules as $rule) {
            try {
                $result = $evaluator->evaluateRule($rule);
                $this->line("{$rule->robot->robot_code} / {$rule->axis}: {$result}");
            } catch (\Throwable $e) {
                $this->error("Rule {$rule->id}: {$e->getMessage()}");
                return self::FAILURE;
            }
        }
        $this->info("Evaluated {$rules->count()} enabled rule(s).");
        return self::SUCCESS;
    }
}
