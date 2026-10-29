<?php

declare(strict_types=1);
namespace App\Services\Rms;

/** No physical RUL assumptions; simple time-trend of verified inspection values. */
final class TrendEstimator
{
    /** @param array<int,array{x:float,y:float}> $points @return array{intercept:float,slope:float}|null */
    public static function fit(array $points): ?array
    {
        if (count($points)<2) return null;
        $n=count($points); $sx=0.0; $sy=0.0;
        foreach($points as $p){ if(!is_finite($p['x'])||!is_finite($p['y']))return null; $sx+=$p['x']; $sy+=$p['y']; }
        $mx=$sx/$n; $my=$sy/$n; $xx=0.0; $xy=0.0;
        foreach($points as $p){$dx=$p['x']-$mx; $xx+=$dx*$dx; $xy+=$dx*($p['y']-$my);}
        if($xx<=0)return null;
        $slope=$xy/$xx;
        return ['intercept'=>$my-$slope*$mx,'slope'=>$slope];
    }
    /** @param array<int,array{x:float,y:float}> $points */
    public static function mae(array $points, array $model): ?float
    {
        if($points===[])return null;
        $e=0.0;foreach($points as $p){$e+=abs($p['y']-($model['intercept']+$model['slope']*$p['x']));}
        return $e/count($points);
    }
    /** Root-mean-square forecast error on strictly held-out inspection measurements. */
    public static function rmse(array $points, array $model): ?float
    {
        if($points===[])return null;
        $sum=0.0;
        foreach($points as $p){
            $err=$p['y']-($model['intercept']+$model['slope']*$p['x']);
            $sum+=$err*$err;
        }
        return sqrt($sum/count($points));
    }

}
