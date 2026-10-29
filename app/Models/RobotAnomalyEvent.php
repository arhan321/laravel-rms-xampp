<?php
namespace App\Models;
use App\Models\RmsModel as Model;
class RobotAnomalyEvent extends Model
{
    protected $guarded=['id'];
    protected function casts(): array {return ['score'=>'float','robust_z'=>'float','observed_value'=>'float','started_at'=>'immutable_datetime','last_seen_at'=>'immutable_datetime','acknowledged_at'=>'immutable_datetime','reviewed_at'=>'immutable_datetime','last_evaluated_at'=>'immutable_datetime','cleared_at'=>'immutable_datetime','recovery_samples'=>'integer','last_evaluated_sample_id'=>'integer'];}
    public function robot(){return $this->belongsTo(Robot::class);}
}

