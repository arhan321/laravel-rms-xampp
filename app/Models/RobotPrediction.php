<?php
namespace App\Models;
use App\Models\RmsModel as Model;
class RobotPrediction extends Model
{
    protected $guarded=['id'];
    protected function casts():array{return ['remaining_calendar_days'=>'float','observed_value'=>'float','generated_at'=>'immutable_datetime','predicted_threshold_at'=>'immutable_datetime','feedback_at'=>'immutable_datetime'];}
    public function robot(){return $this->belongsTo(Robot::class);}
    public function model(){return $this->belongsTo(RobotPredictionModel::class,'model_id');}
}

