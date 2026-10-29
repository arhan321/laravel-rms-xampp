<?php

namespace Tests\Unit;

use App\Services\Rms\AlarmTextParser;
use PHPUnit\Framework\TestCase;

final class AlarmTextParserTest extends TestCase
{
    public function test_recognizes_clean_snapshot(): void
    {
        $r=(new AlarmTextParser())->parse('No active alarms.');
        self::assertSame(0,$r['reported_count']);
        self::assertTrue($r['complete']);
    }
    public function test_retains_partial_snapshot_from_dojo(): void
    {
        $r=(new AlarmTextParser())->parse("4 alarms active.\nAlarm 1 : Code: 100\nSubcode: 1\nTime: 2026/10/14 16:07\nName: COMMUNICATION ERROR(SDCA01#1)");
        self::assertSame(4,$r['reported_count']);
        self::assertCount(1,$r['alarms']);
        self::assertFalse($r['complete']);
        self::assertSame('100',$r['alarms'][0]['alarm_code']);
    }
}
