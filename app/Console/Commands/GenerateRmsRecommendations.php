<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Robot;
use App\Services\Rms\RobotHealthEngine;
use Illuminate\Console\Command;

final class GenerateRmsRecommendations extends Command
{
    protected $signature = 'rms:recommendations-generate {--robot= : Optional robot ID}';
    protected $description = 'Evaluate evidence-based robot health and generate non-duplicate maintenance recommendations';

    public function handle(RobotHealthEngine $engine): int
    {
        if (!config('rms_recommendations.auto_enabled', false)) {
            $this->warn('Auto recommendation is OFF. Set RMS_RECOMMENDATION_AUTO_ENABLED=true when rules and data are approved.');
            return self::FAILURE;
        }
        $query = Robot::query()->where('is_active', true);
        if ($this->option('robot') !== null) {
            $id = filter_var($this->option('robot'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id) { $this->error('Robot ID must be a positive integer.'); return self::INVALID; }
            $query->whereKey($id);
        }
        $count = 0;
        foreach ($query->cursor() as $robot) {
            try {
                $assessment = $engine->assessAndStore($robot);
                $this->line($robot->robot_code.' : '.$assessment->health_status);
                $count++;
            } catch (\Throwable $error) {
                report($error);
                $this->error('Health evaluation failed for '.$robot->robot_code.'; inspect Laravel logs.');
                return self::FAILURE;
            }
        }
        $this->info('Evaluated '.$count.' robot(s). Recommendation duplicates are handled by existing health engine.');
        return self::SUCCESS;
    }
}
