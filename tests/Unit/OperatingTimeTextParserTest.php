<?php

namespace Tests\Unit;

use App\Services\Rms\OperatingTimeTextParser;
use PHPUnit\Framework\TestCase;

final class OperatingTimeTextParserTest extends TestCase
{
    public function test_elapsed_time_in_dump(): void
    {
        $result=(new OperatingTimeTextParser())->parse("Start Time Epoch: 1552383960\nElapsed Time: 12978 Hours : 19 Minutes : 22 Seconds.");
        self::assertSame(12978*3600+19*60+22,$result['seconds']);
    }
    public function test_rejects_invalid_time(): void
    {
        self::assertNull((new OperatingTimeTextParser())->parse('Elapsed Time: 4 Hours : 90 Minutes : 00 Seconds.'));
    }
}
