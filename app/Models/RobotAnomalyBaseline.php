<?php
namespace App\Models;
use App\Models\RmsModel as Model;
class RobotAnomalyBaseline extends Model
{
    protected $guarded=['id'];
    protected function casts(): array {return ['median_value'=>'float','mad_value'=>'float','sample_count'=>'integer','window_start'=>'immutable_datetime','window_end'=>'immutable_datetime'];}
}

