<?php

declare(strict_types=1);

namespace App\Services\Rms;

/**
 * Extracts a display candidate from known collector payload shapes.
 * Parsing SDK text does not verify that YMConnect returned a trustworthy job.
 */
final class JobTextParser
{
    /** @return array{name:?string,source:?string,verified:bool,raw_available:bool} */
    public function parse(?array $payload, ?string $explicitName = null): array
    {
        $payload ??= [];

        foreach ([
            [$explicitName, 'telemetry.job_name'],
            [data_get($payload, 'job.name'), 'raw_payload.job.name'],
        ] as [$candidate, $source]) {
            $name = $this->clean(is_string($candidate) ? $candidate : null);
            if ($name !== null) {
                return ['name' => $name, 'source' => $source, 'verified' => false, 'raw_available' => true];
            }
        }

        $texts = [
            [data_get($payload, 'job.sdk_job_text'), 'raw_payload.job.sdk_job_text'],
            [data_get($payload, 'sdk_job_text'), 'raw_payload.sdk_job_text'],
            [data_get($payload, 'job_read.raw'), 'raw_payload.job_read.raw'],
        ];

        foreach ($texts as [$text, $source]) {
            if (!is_string($text) || trim($text) === '' || strlen($text) > 8192) {
                continue;
            }

            $decoded = json_decode($text, true);
            if (is_array($decoded)) {
                $nested = data_get($decoded, 'sdk_job_text') ?? data_get($decoded, 'job.name');
                if (is_string($nested) && trim($nested) !== '') {
                    $text = $nested;
                }
            }

            $candidate = $this->fromText($text);
            if ($candidate !== null) {
                return ['name' => $candidate, 'source' => $source, 'verified' => false, 'raw_available' => true];
            }
        }

        $rawAvailable = false;
        foreach ($texts as [$text]) {
            if (is_string($text) && trim($text) !== '') {
                $rawAvailable = true;
                break;
            }
        }

        return ['name' => null, 'source' => null, 'verified' => false, 'raw_available' => $rawAvailable];
    }

    private function fromText(string $text): ?string
    {
        if (strlen($text) > 8192) {
            return null;
        }

        $pattern = '/(?:^|\R)\s*(?:Executing\s+)?(?:Job\s*Name|JobName|Job)\s*[:=]\s*(?:"([^"\r\n]{1,120})"|\'([^\'\r\n]{1,120})\'|([^\r\n]{1,120}))/i';
        if (!preg_match($pattern, $text, $match)) {
            return null;
        }

        return $this->clean(($match[1] ?? '') !== '' ? $match[1] : (($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? '')));
    }

    private function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value, " \t\n\r\0\x0B\"'");
        $value = rtrim($value, ",;");
        if ($value === '' || strlen($value) > 120 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return null;
        }

        return $value;
    }
}
