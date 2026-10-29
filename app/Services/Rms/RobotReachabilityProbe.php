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
        $timeout = (int) config('rms.robot_probe.timeout_seconds', 2);

        if (! filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->result(false, 'invalid', 'Alamat harus berupa IPv4 yang valid.', 'validation', $checkedAt);
        }

        if (! config('rms.robot_probe.enabled', true)) {
            return $this->result(false, 'disabled', 'Pemeriksaan IP sedang dinonaktifkan. Hubungi administrator RMS.', 'configuration', $checkedAt);
        }

        $binary = (string) config('rms.robot_probe.ping_binary', '');
        if ($binary === '') {
            foreach (['/usr/bin/ping', '/bin/ping', 'ping'] as $candidate) {
                if ($candidate === 'ping' || is_executable($candidate)) {
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
            [$binary, '-n', '-c', '1', '-W', (string) $timeout, $ipAddress],
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

        if ($exitCode === 0) {
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
