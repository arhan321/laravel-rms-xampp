<?php

declare(strict_types=1);
namespace App\Console\Commands;
use App\Models\Robot;
use App\Services\Rms\AnomalyEngine;
use Illuminate\Console\Command;
final class EvaluateRmsAnomaly extends Command
{
    protected $signature='rms:anomaly-evaluate {--robot= : Optional robot ID}';
    protected $description='Detect statistical torque deviations (review required)';
    public function handle(AnomalyEngine $engine): int
    {
        $query=Robot::query()->where('is_active',true);
        if($id=$this->option('robot'))$query->whereKey((int)$id);
        foreach($query->cursor() as $robot)$this->line($robot->robot_code.' '.json_encode($engine->inspectLatest($robot)));
        return self::SUCCESS;
    }
}
