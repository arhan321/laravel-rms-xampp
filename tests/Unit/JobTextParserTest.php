<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Rms\JobTextParser;
use PHPUnit\Framework\TestCase;

final class JobTextParserTest extends TestCase
{
    public function test_reads_the_collectors_sdk_job_text_shape(): void
    {
        $result = (new JobTextParser())->parse([
            'job' => [
                'sdk_job_text' => "Job Name: DOJO_PICK_AND_PLACE\nStatus: running",
                'job_name_verified' => false,
            ],
        ]);

        self::assertSame('DOJO_PICK_AND_PLACE', $result['name']);
        self::assertSame('raw_payload.job.sdk_job_text', $result['source']);
        self::assertFalse($result['verified']);
    }

    public function test_accepts_structured_job_name_as_unverified_candidate(): void
    {
        $result = (new JobTextParser())->parse(['job' => ['name' => 'CELL A MAIN']]);

        self::assertSame('CELL A MAIN', $result['name']);
        self::assertSame('raw_payload.job.name', $result['source']);
        self::assertFalse($result['verified']);
    }

    public function test_does_not_invent_name_from_unrecognized_text(): void
    {
        $result = (new JobTextParser())->parse(['job' => ['sdk_job_text' => 'SDK call failed']]);

        self::assertNull($result['name']);
        self::assertTrue($result['raw_available']);
    }
}
