<?php

declare(strict_types=1);

namespace App\Services\Rms;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SpeedReducerOemImport
{
    public const GROUP_PATTERN = '/^[RS][1-9][0-9]?$/D';

    public const COLUMNS = [
        'date_wib', 'control_group', 'axis', 'measurement_pct', 'variation_pp',
        'operating_hours', 'remaining_life_hours', 'variation_lower_pp',
        'variation_upper_pp', 'life_lower_hours', 'life_upper_hours',
    ];

    private const NUMERIC_COLUMNS = [
        'measurement_pct', 'variation_pp', 'operating_hours', 'remaining_life_hours',
        'variation_lower_pp', 'variation_upper_pp', 'life_lower_hours', 'life_upper_hours',
    ];

    /** @return array{count: int, first_group: string} */
    public function store(int $robotId, string $csv, string $sourceReference, string $verifiedBy): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($csv));
        $header = $this->split(preg_replace('/^\xEF\xBB\xBF/', '', array_shift($lines)));
        if ($header !== self::COLUMNS) {
            $this->fail(1, 'Header CSV tidak sesuai. Salin header dari form impor.');
        }
        if (count($lines) > 1500) {
            $this->fail(1, 'Maksimal 1.500 baris data per impor. Bagi histori ke beberapa file.');
        }

        $rows = [];
        $stamp = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        $todayWib = CarbonImmutable::now('Asia/Jakarta')->toDateString();
        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            $n = $index + 2;
            $cells = $this->split($line);
            if (count($cells) !== count(self::COLUMNS)) {
                $this->fail($n, 'Jumlah kolom salah. Pertahankan koma untuk kolom kosong.');
            }
            $row = array_combine(self::COLUMNS, $cells);
            [$year, $month, $day] = array_pad(explode('-', $row['date_wib'], 3), 3, 0);
            if (! preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $row['date_wib'])
                || ! checkdate((int) $month, (int) $day, (int) $year)
                || $row['date_wib'] < '2000-01-01' || $row['date_wib'] > $todayWib) {
                $this->fail($n, 'Tanggal WIB harus YYYY-MM-DD, mulai tahun 2000 hingga hari ini.');
            }
            $row['control_group'] = strtoupper($row['control_group']);
            $row['axis'] = strtoupper($row['axis']);
            if (! preg_match(self::GROUP_PATTERN, $row['control_group'])) {
                $this->fail($n, 'Gunakan grup dari sumber OEM, misalnya R1 atau S1.');
            }
            $station = str_starts_with($row['control_group'], 'S');
            if (! in_array($row['axis'], ['S', 'L', 'U', 'R', 'B', 'T'], true)
                && ! ($station && preg_match('/^[1-8]$/D', $row['axis']))) {
                $this->fail($n, 'Gunakan axis S, L, U, R, B, T; axis station dapat memakai nomor 1–8.');
            }
            foreach (self::NUMERIC_COLUMNS as $column) {
                $value = $row[$column];
                $prefix = in_array($column, ['variation_pp', 'variation_lower_pp', 'variation_upper_pp'], true) ? '-?' : '';
                if ($value !== '' && ! preg_match('/^'.$prefix.'[0-9]{1,8}(?:\.[0-9]{1,6})?$/D', $value)) {
                    $this->fail($n, "Kolom {$column}: gunakan angka dengan titik desimal atau kosongkan.");
                }
                $row[$column] = $value === '' ? null : $value;
            }
            if (($row['operating_hours'] === null) !== ($row['remaining_life_hours'] === null)) {
                $this->fail($n, 'Jam operasi dan sisa umur harus diisi berpasangan.');
            }
            if ($row['measurement_pct'] === null && $row['variation_pp'] === null && $row['remaining_life_hours'] === null) {
                $this->fail($n, 'Isi minimal satu nilai Measurement, Variation, atau Life.');
            }

            foreach ([
                ['variation_lower_pp', 'variation_upper_pp', 'variation_pp'],
                ['life_lower_hours', 'life_upper_hours', 'remaining_life_hours'],
            ] as [$lower, $upper, $metric]) {
                if (($row[$lower] !== null || $row[$upper] !== null) && $row[$metric] === null) {
                    $this->fail($n, "Batas {$metric} membutuhkan hasil pengukuran terkait.");
                }
                if ($row[$lower] !== null && $row[$upper] !== null && (float) $row[$lower] >= (float) $row[$upper]) {
                    $this->fail($n, "Batas bawah {$metric} harus lebih kecil dari batas atas.");
                }
            }

            $key = implode('|', [$row['control_group'], $row['axis'], $row['date_wib']]);
            if (isset($rows[$key])) {
                $this->fail($n, 'Grup, axis, dan tanggal sudah dicantumkan dalam batch ini.');
            }
            $rows[$key] = ['robot_id' => $robotId] + $row + [
                'source_reference' => $sourceReference,
                'verified_by' => $verifiedBy,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ];
        }

        if ($rows === []) {
            $this->fail(2, 'Masukkan minimal satu baris data OEM.');
        }

        DB::transaction(function () use ($rows): void {
            foreach (array_chunk(array_values($rows), 50) as $batch) {
                DB::table('robot_speed_reducer_readings')->upsert(
                    $batch,
                    ['robot_id', 'control_group', 'date_wib', 'axis'],
                    [...self::NUMERIC_COLUMNS, 'source_reference', 'verified_by', 'updated_at'],
                );
            }
        });

        return [
            'count' => count($rows),
            'first_group' => $rows[array_key_first($rows)]['control_group'],
        ];
    }

    /** @return list<string> */
    private function split(string $line): array
    {
        return array_map(static fn (?string $cell): string => trim($cell ?? ''), str_getcsv($line, ',', '"', ''));
    }

    private function fail(int $line, string $message): never
    {
        throw ValidationException::withMessages(['csv' => "Baris {$line}: {$message}"]);
    }
}
