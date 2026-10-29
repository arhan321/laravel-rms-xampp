<?php

declare(strict_types=1);

namespace App\Services\Rms;

/**
 * Exact-format parser for a diagnostic string printed by YMConnect TimeData.
 * Parsing proves only string format. OEM/pendant verification remains required
 * before a value can be used for PM decisions.
 */
final class OperatingTimeTextParser
{
    /** @return array{seconds:int,hours:float,source:string}|null */
    public function parse(?string $raw): ?array
    {
        if ($raw === null || strlen($raw) > 8192) return null;
        if (!preg_match('/(?:^|\n)Elapsed\s+Time\s*:\s*(\d{1,8})\s+Hours\s*:\s*(\d{1,2})\s+Minutes\s*:\s*(\d{1,2})\s+Seconds\s*\.?\s*(?:\n|$)/i', $raw, $m)) {
            return null;
        }
        [$hours, $minutes, $seconds] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if ($minutes > 59 || $seconds > 59 || $hours > 10000000) return null;
        $total = $hours * 3600 + $minutes * 60 + $seconds;
        return ['seconds'=>$total, 'hours'=>round($total / 3600, 4), 'source'=>'sdk_text_elapsed_time'];
    }
}
