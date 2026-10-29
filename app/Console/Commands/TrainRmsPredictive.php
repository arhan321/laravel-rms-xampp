<?php

declare(strict_types=1);
namespace App\Console\Commands;
use App\Models\Robot;
use App\Services\Rms\PredictiveEngine;
use Illuminate\Console\Command;
final class TrainRmsPredictive extends Command
{
    protected $signature='rms:predictive-train {--robot= : Optional robot ID}';
    protected $description='Version and time-validate experimental trends from verified reducer inspections';
    public function handle(PredictiveEngine $engine): int
    {
        $query=Robot::query()->where('is_active',true);
        if($id=$this->option('robot'))$query->whereKey((int)$id);
        foreach($query->cursor() as $robot)$this->line($robot->robot_code.' '.json_encode($engine->trainRobot($robot)));
        return self::SUCCESS;
    }
}
