<?php

declare(strict_types=1);

namespace App\Services\Rms;

/**
 * Non-invasive network preflight for a controller registry form.
 *
 * This service never sends a YMConnect command and never writes to the robot.
 * ICMP reachability proves that a device answers at the address from the
 * Laravel host. The real Yaskawa identity is still confirmed by the C++
 * YMConnect collector when it establishes the first connected event.
 */
final class RobotReachabilityProbe
{
    /** @return array{ok: bool, status: string, message: string, method: string, checked_at: string, latency_ms: float|null} */
    public function probe(string $ipAddress): array
    {
        $checkedAt = now()->toIso8601String();
        $timeout = max(1, min(5, (int) config('rms.robot_probe.timeout_seconds', 2)));
        $isWindows = PHP_OS_FAMILY === 'Windows';

        if (! filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->result(false, 'invalid', 'Alamat harus berupa IPv4 yang valid.', 'validation', $checkedAt);
        }

        if (! config('rms.robot_probe.enabled', true)) {
            return $this->result(false, 'disabled', 'Pemeriksaan IP sedang dinonaktifkan. Hubungi administrator RMS.', 'configuration', $checkedAt);
        }

        if (! function_exists('proc_open')) {
            return $this->result(false, 'unavailable', 'Fungsi proc_open pada PHP belum tersedia. Periksa disable_functions pada php.ini yang digunakan Laravel.', 'icmp', $checkedAt);
        }

        $binary = trim((string) config('rms.robot_probe.ping_binary', ''));
        if ($binary === '') {
            $systemRoot = rtrim((string) (getenv('SystemRoot') ?: getenv('WINDIR') ?: 'C:\\Windows'), '\\/');
            $candidates = $isWindows
                ? [$systemRoot.'\\System32\\PING.EXE', 'ping.exe']
                : ['/usr/bin/ping', '/bin/ping', 'ping'];
            foreach ($candidates as $candidate) {
                if (in_array($candidate, ['ping', 'ping.exe'], true) || is_executable($candidate)) {
                    $binary = $candidate;
                    break;
                }
            }
        }

        if ($binary === '') {
            return $this->result(false, 'unavailable', 'Pemeriksaan IP belum tersedia pada komputer monitoring. Hubungi administrator RMS.', 'icmp', $checkedAt);
        }

        $started = microtime(true);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open(
            $this->pingCommand($binary, $ipAddress, $timeout, $isWindows),
            $descriptors,
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );

        if (! is_resource($process)) {
            return $this->result(false, 'unavailable', 'Komputer monitoring tidak dapat menjalankan pemeriksaan jaringan. Hubungi administrator RMS.', 'icmp', $checkedAt);
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $latency = round((microtime(true) - $started) * 1000, 1);

        // Windows can return exit code 0 for a router's "Destination host
        // unreachable" response. An IPv4 echo reply also has a TTL field.
        $hasEchoReply = ! $isWindows || preg_match('/\bTTL\s*=\s*\d+\b/i', (string) $stdout) === 1;
        if ($exitCode === 0 && $hasEchoReply) {
            return [
                'ok' => true,
                'status' => 'reachable',
                'message' => "IP {$ipAddress} dapat dijangkau dari komputer monitoring ({$latency} ms). Identitas controller Yaskawa akan dikonfirmasi saat data controller pertama diterima.",
                'method' => 'icmp',
                'checked_at' => $checkedAt,
                'latency_ms' => $latency,
            ];
        }

        return $this->result(false, 'unreachable', "IP {$ipAddress} tidak dapat dijangkau dari komputer monitoring. Periksa alamat IP, daya controller, kabel, dan jaringan.", 'icmp', $checkedAt, $latency);
    }

    /** @return list<string> */
    private function pingCommand(string $binary, string $ipAddress, int $timeout, bool $isWindows): array
    {
        // Linux -W uses seconds; Windows -w uses milliseconds.
        return $isWindows
            ? [$binary, '-4', '-n', '1', '-w', (string) ($timeout * 1000), $ipAddress]
            : [$binary, '-n', '-c', '1', '-W', (string) $timeout, $ipAddress];
    }

    /** @return array{ok: bool, status: string, message: string, method: string, checked_at: string, latency_ms: float|null} */
    private function result(bool $ok, string $status, string $message, string $method, string $checkedAt, ?float $latency = null): array
    {
        // Use explicit keys because the local variable names are camelCase
        // while the API payload uses snake_case.
        return [
            'ok' => $ok,
            'status' => $status,
            'message' => $message,
            'method' => $method,
            'checked_at' => $checkedAt,
            'latency_ms' => $latency,
        ];
    }
}
