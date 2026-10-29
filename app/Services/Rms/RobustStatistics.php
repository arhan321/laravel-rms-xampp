<?php

declare(strict_types=1);
namespace App\Services\Rms;

final class RobustStatistics
{
    /** @param array<int,float|int> $values */
    public static function median(array $values): float
    {
        if ($values === []) throw new \InvalidArgumentException('median needs data');
        $values = array_map(static fn ($v): float => (float)$v, $values);
        foreach ($values as $v) if (!is_finite($v)) throw new \InvalidArgumentException('Non-finite data');
        sort($values, SORT_NUMERIC);
        $n = count($values); $i = intdiv($n,2);
        return $n % 2 ? $values[$i] : ($values[$i-1]+$values[$i])/2;
    }
    /** @param array<int,float|int> $values @return array{median:float,mad:float,n:int} */
    public static function summarize(array $values): array
    {
        $m = self::median($values);
        return ['median'=>$m,'mad'=>self::median(array_map(static fn($x) => abs($x-$m), $values)), 'n'=>count($values)];
    }
    public static function z(float $value, float $median, float $mad): ?float
    {
        if ($mad <= 0 || !is_finite($mad)) return null; // no unsafe score on degenerate baseline
        return abs($value-$median) / (1.4826*$mad);
    }
}
