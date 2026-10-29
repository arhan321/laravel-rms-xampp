<?php

declare(strict_types=1);
namespace App\Console\Commands;
use App\Models\Robot;
use App\Services\Rms\RobotHealthEngine;
use Illuminate\Console\Command;

final class EvaluateRobotHealth extends Command
{
    protected $signature='rms:health-evaluate {--robot= : Optional robot ID}';
    protected $description='Persist evidence-based RMS health (unknown when evidence is incomplete)';
    public function handle(RobotHealthEngine $engine): int
    {
        $query=Robot::query()->where('is_active',true);
        if($id=$this->option('robot')) $query->whereKey((int)$id);
        $count=0;
        foreach($query->cursor() as $robot){
            $a=$engine->assessAndStore($robot);
            $this->line($robot->robot_code.' '.$a->health_status.' #'.$a->id);
            $count++;
        }
        return $count ? self::SUCCESS : self::FAILURE;
    }
}
