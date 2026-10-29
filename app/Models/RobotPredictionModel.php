<?php
namespace App\Models;
use App\Models\RmsModel as Model;
class RobotPredictionModel extends Model
{
    protected $guarded=['id'];
    protected function casts():array{return ['parameters'=>'array','is_current'=>'boolean','train_count'=>'integer','validation_count'=>'integer','validation_mae'=>'float','validation_rmse'=>'float','trained_at'=>'immutable_datetime','window_start'=>'immutable_datetime','window_end'=>'immutable_datetime'];}
}

