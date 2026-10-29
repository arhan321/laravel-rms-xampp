<?php

declare(strict_types=1);

namespace App\Services\Rms;

/** Strict raw YMConnect text parser: does NOT invent alarm records or severity. */
final class AlarmTextParser
{
    /** @return array{recognized:bool,reported_count:?int,complete:bool,alarms:array} */
    public function parse(?string $text): array
    {
        $unknown = ['recognized'=>false,'reported_count'=>null,'complete'=>false,'alarms'=>[]];
        if ($text === null || trim($text) === '' || strlen($text) > 65535) return $unknown;
        if (preg_match('/^\s*No active alarms\.\s*$/i', $text)) {
            return ['recognized'=>true,'reported_count'=>0,'complete'=>true,'alarms'=>[]];
        }
        if (!preg_match('/^\s*(\d+)\s+alarms?\s+active\./i', $text, $header)) return $unknown;
        $count = (int) $header[1];
        if ($count < 1 || $count > 200) return $unknown;
        preg_match_all('/Alarm\s+(\d+)\s*:\s*Code\s*:\s*([^\r\n]+)(.*?)(?=\bAlarm\s+\d+\s*:\s*Code\s*:|\z)/is', $text, $matches, PREG_SET_ORDER);
        $alarms = [];
        $seen = [];
        foreach ($matches as $block) {
            $code = trim($block[2]);
            $extra = $block[3];
            if (!preg_match('/\bSubcode\s*:\s*([^\r\n]+)/i', $extra, $s)) continue;
            if (!preg_match('/\bName\s*:\s*([^\r\n]+)/i', $extra, $n)) continue;
            $subcode = trim($s[1]); $name = trim($n[1]);
            if ($code === '' || $subcode === '' || $name === '') continue;
            if (strlen($code) > 60 || strlen($subcode) > 60 || strlen($name) > 255) continue;
            $key = $code.'|'.$subcode.'|'.$name;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            preg_match('/\bTime\s*:\s*([^\r\n]+)/i', $extra, $reportedTime);
            $alarms[] = [
                'alarm_code'=>$code,
                'subcode'=>$subcode,
                'alarm_name'=>$name,
                // Store controller display time as evidence only; could have wrong clock.
                'controller_time_raw'=>isset($reportedTime[1]) ? trim($reportedTime[1]) : null,
                'severity'=>null,
            ];
        }
        // A truncated report (e.g. "4 alarms" but 1 details) must stay partial.
        return [
            'recognized'=>true,
            'reported_count'=>$count,
            'complete'=>count($alarms)===$count,
            'alarms'=>$alarms,
        ];
    }
}
