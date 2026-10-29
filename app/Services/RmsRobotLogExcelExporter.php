<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Robot;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Presentation-only report generator. Never writes to the controller or PostgreSQL.
 * The controller supplies the same validated-filter results shown in Robot Logs.
 * No 'hours' is computed from raw SDK values unless value_seconds was verified.
 */
final class RmsRobotLogExcelExporter
{
    private const TIMEZONE = 'Asia/Jakarta';
    private const AXES = ['S', 'L', 'U', 'R', 'B', 'T'];

    private const DETAILS = [
        'operating' => ['Operating Time', 'OPERATING TIME · DETAIL PEMBACAAN',
            ['Waktu WIB', 'Robot', 'Nama Robot', 'Tipe Waktu', 'Status Baca', 'Nilai Mentah SDK', 'Detik Tervalidasi', 'Jam Tervalidasi', 'Kode SDK', 'Pesan SDK', 'ID Data'],
            [24, 16, 28, 28, 16, 34, 21, 19, 13, 52, 12], 5],
        'alarm_event' => ['Alarm Events', 'ALARM EVENTS · DETAIL KEJADIAN',
            ['Waktu Mulai WIB', 'Robot', 'Nama Robot', 'Kode Alarm', 'Subcode', 'Nama Alarm', 'Severity', 'Status', 'Terakhir Dilihat WIB', 'Clear WIB', 'Sumber', 'Detail / Temuan', 'Kunci Event', 'ID Data'],
            [24, 17, 27, 18, 15, 43, 17, 16, 24, 24, 20, 50, 28, 12], 8],
        'alarm_snapshot' => ['Alarm Snapshot', 'ALARM SNAPSHOTS · PEMBACAAN SDK',
            ['Waktu WIB', 'Robot', 'Nama Robot', 'Status Baca', 'Jumlah Alarm', 'Teks Alarm SDK', 'Alarm Terurai', 'Kode SDK', 'Pesan SDK', 'ID Data'],
            [24, 16, 28, 17, 17, 58, 50, 15, 45, 12], 4],
        'telemetry' => ['Telemetry SLURBT', 'TELEMETRY · TORQUE DAN POSITION PULSE',
            ['Waktu WIB', 'Robot', 'Nama Robot', 'Position OK', 'Torque OK', 'Satuan Torque',
            'Torque S', 'Torque L', 'Torque U', 'Torque R', 'Torque B', 'Torque T',
            'Pulse S', 'Pulse L', 'Pulse U', 'Pulse R', 'Pulse B', 'Pulse T',
            'Sequence', 'Kode Position', 'Kode Torque', 'ID Data'],
            [24, 16, 26, 16, 16, 19, 14, 14, 14, 14, 14, 14, 16, 16, 16, 16, 16, 16, 14, 17, 16, 12], 5],
        'connection' => ['Koneksi Robot', 'CONNECTION EVENTS · STATUS KOMUNIKASI',
            ['Waktu WIB', 'Robot', 'Nama Robot', 'Status Koneksi', 'IP Controller', 'Pesan / Keterangan', 'ID Data'],
            [24, 16, 28, 18, 22, 68, 12], 4],
        'health' => ['Equipment Health', 'EQUIPMENT HEALTH · PENILAIAN',
            ['Waktu WIB', 'Robot', 'Nama Robot', 'Status Health', 'Ringkasan', 'Versi Aturan', 'Sumber Penilaian', 'Indikator', 'ID Data'],
            [24, 16, 28, 18, 55, 19, 23, 56, 12], 4],
        'maintenance' => ['Maintenance', 'MAINTENANCE · JURNAL PEKERJAAN',
            ['Waktu WIB', 'Robot', 'Nama Robot', 'Jenis Tindakan', 'Hasil', 'Teknisi', 'Temuan', 'Tindakan Dilakukan', 'Mulai WIB', 'Selesai WIB', 'Verifikasi Oleh', 'Verifikasi WIB', 'ID Data'],
            [24, 16, 28, 18, 20, 24, 55, 55, 24, 24, 26, 24, 12], 5],
        'recommendation' => ['Rekomendasi', 'REKOMENDASI · TINDAKAN MAINTENANCE',
            ['Waktu WIB', 'Robot', 'Nama Robot', 'Status', 'Judul Rekomendasi', 'Prioritas', 'Tindakan Disarankan', 'Sumber', 'Bukti', 'ID Data'],
            [24, 16, 28, 18, 48, 16, 68, 20, 50, 12], 4],
    ];

    /**
     * @param array<int,array<string,mixed>> $entries  Sorted newest first.
     * @param array<string,int> $totalCounts          Uncapped counts from DB per selected type.
     * @param array<int,string> $selected             Selected type IDs.
     * @param array<string,string> $typeLabels
     */
    public function write(array $entries, array $filters, array $totalCounts, array $selected, array $typeLabels, int $limit): void
    {
        $book = new Spreadsheet();
        $summary = $book->getActiveSheet();
        $summary->setTitle('Ringkasan');
        $this->summary($summary, $entries, $filters, $totalCounts, $selected, $typeLabels, $limit);

        $all = $book->createSheet();
        $all->setTitle('Riwayat Log');
        $allHeaders = ['No', 'Waktu WIB', 'Kode Robot', 'Nama Robot', 'Jenis Log', 'Status', 'Ringkasan', 'Detail Penting', 'ID Data'];
        $this->setupSheet($all, 'RIWAYAT LOG · SELURUH HASIL', 'Urutan waktu terbaru • detail tiap jenis tersedia di tab khusus',
            $allHeaders, [9, 24, 17, 30, 24, 18, 55, 76, 12]);

        $sheets = [];
        foreach ($selected as $type) {
            if (!isset(self::DETAILS[$type])) continue;
            [$tab, $heading, $headers, $widths] = self::DETAILS[$type];
            $sheet = $book->createSheet();
            $sheet->setTitle($tab);
            $this->setupSheet($sheet, $heading, 'Data hasil filter • waktu WIB • kosong = tidak tersedia / belum diverifikasi', $headers, $widths);
            $sheets[$type] = $sheet;
        }

        $rowCounters = array_fill_keys($selected, 0);
        foreach ($entries as $i => $entry) {
            $details = is_array($entry['details'] ?? null) ? $entry['details'] : [];
            $row = 9 + $i;
            $allCells = [
                $i + 1,
                $this->excelDate($entry['time_wib']),
                $entry['robot_code'], $entry['robot_name'], $entry['type_label'],
                $entry['status'], $entry['summary'],
                $this->importantDetails((string) $entry['type'], $details), $entry['record_id'],
            ];
            $this->putRow($all, $row, $allCells);
            $this->paintStatus($all, 'F' . $row, (string) $entry['status']);

            $type = (string) $entry['type'];
            if (isset($sheets[$type])) {
                $rowCounters[$type]++;
                $this->putRow($sheets[$type], 8 + $rowCounters[$type], $this->typeRow($entry, $details));
                $statusColumn = self::DETAILS[$type][4];
                $this->paintStatus($sheets[$type], Coordinate::stringFromColumnIndex($statusColumn) . (8 + $rowCounters[$type]), (string) $entry['status']);
            }
        }

        $this->finish($all, count($entries), count($allHeaders), ['B']);
        foreach ($sheets as $type => $sheet) {
            $headers = self::DETAILS[$type][2];
            $dateCols = match ($type) {
                'alarm_event' => ['A','I','J'],
                'maintenance' => ['A','I','J','L'],
                default => ['A'],
            };
            $this->finish($sheet, $rowCounters[$type], count($headers), $dateCols);
        }
        // Reapply status pills AFTER alternating-row backgrounds are painted.
        $typeOffsets = array_fill_keys($selected, 0);
        foreach ($entries as $i => $entry) {
            $this->paintStatus($all, 'F' . ($i + 9), (string) $entry['status']);
            $type = (string) $entry['type'];
            if (isset($sheets[$type])) {
                $typeOffsets[$type]++;
                $column = Coordinate::stringFromColumnIndex(self::DETAILS[$type][4]);
                $this->paintStatus($sheets[$type], $column . ($typeOffsets[$type] + 8), (string) $entry['status']);
            }
        }
        $book->setActiveSheetIndex(0);
        (new Xlsx($book))->save('php://output');
        $book->disconnectWorksheets();
    }

    /** @return array<int,mixed> */
    private function typeRow(array $entry, array $d): array
    {
        $time = $this->excelDate((string) $entry['time_wib']);
        $robot = (string) $entry['robot_code'];
        $name = (string) $entry['robot_name'];
        $id = (int) $entry['record_id'];
        $status = (string) $entry['status'];

        return match ($entry['type']) {
            'operating' => [
                $time, $robot, $name, $this->display($d['time_type'] ?? null), $status,
                $this->display($d['raw_value'] ?? null),
                $this->verifiedNumber($d['value_seconds'] ?? null),
                $this->verifiedHours($d['value_seconds'] ?? null),
                $this->numberOrBlank($d['status_code'] ?? null), $this->display($d['message'] ?? null), $id,
            ],
            'alarm_event' => [
                $time, $robot, $name, $this->display($d['alarm_code'] ?? null),
                $this->display($d['subcode'] ?? null), $this->display($d['alarm_name'] ?? null),
                $this->display($d['severity'] ?? null), $status,
                $this->timeFromIso($d['last_seen_at'] ?? null), $this->timeFromIso($d['cleared_at'] ?? null),
                $this->display($d['source'] ?? null),
                $this->display($d['details'] ?? null), $this->display($d['event_key'] ?? null), $id,
            ],
            'alarm_snapshot' => [
                $time, $robot, $name, $status,
                $this->numberOrBlank($d['active_alarm_count'] ?? null),
                $this->display($d['sdk_text'] ?? null), $this->display($d['parsed_alarms'] ?? null),
                $this->numberOrBlank($d['status_code'] ?? null), $this->display($d['message'] ?? null), $id,
            ],
            'telemetry' => [
                $time, $robot, $name, $this->boolText($d['position_ok'] ?? null), $this->boolText($d['torque_ok'] ?? null),
                $this->display($d['torque_unit'] ?? null),
                ...array_map(fn ($a) => $this->numberOrBlank($this->axis($d['torque_axes'] ?? null, $a)), self::AXES),
                ...array_map(fn ($a) => $this->numberOrBlank($this->axis($d['position_pulses'] ?? null, $a)), self::AXES),
                $this->numberOrBlank($d['sequence'] ?? null),
                $this->numberOrBlank($d['position_status_code'] ?? null),
                $this->numberOrBlank($d['torque_status_code'] ?? null), $id,
            ],
            'connection' => [
                $time, $robot, $name, $status,
                $this->display($d['ip_address'] ?? null), $this->display($entry['summary'] ?? null), $id,
            ],
            'health' => [
                $time, $robot, $name, $status, $this->display($entry['summary'] ?? null),
                $this->display($d['rule_version'] ?? null), $this->display($d['assessment_source'] ?? null),
                $this->display($d['indicators'] ?? null), $id,
            ],
            'maintenance' => [
                $time, $robot, $name, $this->maintenanceAction((string) ($entry['summary'] ?? '')),
                $status, $this->maintenancePerson((string) ($entry['summary'] ?? '')),
                $this->display($d['findings'] ?? null), $this->display($d['action_taken'] ?? null),
                $this->timeFromIso($d['started_at'] ?? null), $this->timeFromIso($d['completed_at'] ?? null),
                $this->display($d['verified_by'] ?? null), $this->timeFromIso($d['verified_at'] ?? null), $id,
            ],
            'recommendation' => [
                $time, $robot, $name, $status, $this->display($entry['summary'] ?? null),
                $this->display($d['priority'] ?? null), $this->display($d['recommendation'] ?? null),
                $this->display($d['source'] ?? null), $this->display($d['evidence'] ?? null), $id,
            ],
            default => [$time, $robot, $name, $status, $this->display($entry['summary'] ?? null), $id],
        };
    }

    private function importantDetails(string $type, array $d): string
    {
        return match ($type) {
            'operating' => 'Tipe: ' . $this->display($d['time_type'] ?? null)
                . ' | Raw SDK: ' . $this->display($d['raw_value'] ?? null)
                . ' | Detik valid: ' . $this->display($d['value_seconds'] ?? null)
                . ' | Pesan: ' . $this->display($d['message'] ?? null),
            'alarm_event' => 'Alarm: ' . $this->display($d['alarm_code'] ?? null) . ' / ' . $this->display($d['alarm_name'] ?? null)
                . ' | Severity: ' . $this->display($d['severity'] ?? null)
                . ' | Clear: ' . $this->display($d['cleared_at'] ?? null),
            'alarm_snapshot' => 'Jumlah terurai: ' . $this->display($d['active_alarm_count'] ?? null)
                . ' | SDK: ' . $this->display($d['sdk_text'] ?? null),
            'telemetry' => 'Posisi: ' . $this->boolText($d['position_ok'] ?? null)
                . ' | Torque: ' . $this->boolText($d['torque_ok'] ?? null)
                . ' | Unit: ' . $this->display($d['torque_unit'] ?? null),
            'connection' => 'IP: ' . $this->display($d['ip_address'] ?? null) . ' | State: ' . $this->display($d['state'] ?? null),
            'health' => 'Rule: ' . $this->display($d['rule_version'] ?? null)
                . ' | Indikator: ' . $this->display($d['indicators'] ?? null),
            'maintenance' => 'Temuan: ' . $this->display($d['findings'] ?? null)
                . ' | Tindakan: ' . $this->display($d['action_taken'] ?? null),
            'recommendation' => 'Prioritas: ' . $this->display($d['priority'] ?? null)
                . ' | Saran: ' . $this->display($d['recommendation'] ?? null),
            default => '-',
        };
    }

    private function setupSheet(Worksheet $s, string $title, string $subtitle, array $headers, array $widths): void
    {
        $last = Coordinate::stringFromColumnIndex(count($headers));
        $s->setShowGridlines(false);
        $s->getSheetView()->setZoomScale(85);
        $s->mergeCells('A1:' . $last . '2');
        $this->text($s, 'A1', $title);
        $s->getStyle('A1:' . $last . '2')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '184D42']],
            'font' => ['name' => 'Aptos', 'size' => 17, 'bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $s->getRowDimension(1)->setRowHeight(24);
        $s->getRowDimension(2)->setRowHeight(19);
        $s->mergeCells('A3:' . $last . '3');
        $this->text($s, 'A3', $subtitle);
        $s->getStyle('A3')->getFont()->setItalic(true)->getColor()->setRGB('3C685C');
        $s->getRowDimension(3)->setRowHeight(22);
        $s->mergeCells('A5:' . $last . '5');
        $this->text($s, 'A5', 'RMS YASKAWA  •  DETAIL TEKNISI  •  Jakarta (WIB)  •  Indikator pembacaan bukan diagnosis komponen');
        $s->getStyle('A5:' . $last . '5')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EDF5F1']],
            'font' => ['color' => ['rgb' => '365F51'], 'size' => 10],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        foreach ($headers as $i => $header) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $this->text($s, $col . '8', $header);
            $s->getColumnDimension($col)->setWidth(min(70, (float) ($widths[$i] ?? 18)));
        }
        $s->getRowDimension(8)->setRowHeight(34);
        $s->getStyle('A8:' . $last . '8')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '307761']],
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Aptos', 'size' => 10],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B9D8CB']]],
        ]);
        $s->freezePane('D9');
        $s->getPageSetup()->setFitToWidth(1);
        $s->getPageSetup()->setFitToHeight(0);
        $s->getPageSetup()->setOrientation('landscape');
    }

    private function finish(Worksheet $s, int $count, int $columns, array $dateColumns): void
    {
        $last = Coordinate::stringFromColumnIndex($columns);
        if ($count === 0) {
            $s->mergeCells('A9:' . $last . '9');
            $this->text($s, 'A9', 'Tidak ada record untuk jenis log ini pada filter yang dipilih.');
            $s->getStyle('A9:' . $last . '9')->getFont()->getColor()->setRGB('6B7280');
            $s->getRowDimension(9)->setRowHeight(26);
            return;
        }
        $end = $count + 8;
        $s->setAutoFilter('A8:' . $last . $end);
        $s->getStyle('A9:' . $last . $end)->applyFromArray([
            'font' => ['name' => 'Aptos', 'size' => 10, 'color' => ['rgb' => '203A33']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'E0E9E4']]],
        ]);
        foreach ($dateColumns as $col) {
            $s->getStyle("{$col}9:{$col}{$end}")->getNumberFormat()->setFormatCode('dd mmm yyyy hh:mm:ss');
        }
        for ($row = 9; $row <= $end; $row++) {
            if ($row % 2 === 0) {
                $s->getStyle('A' . $row . ':' . $last . $row)->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F8F5');
            }
            $s->getRowDimension($row)->setRowHeight(32);
        }
        $s->getStyle('A8:' . $last . $end)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    }

    private function putRow(Worksheet $sheet, int $row, array $values): void
    {
        foreach (array_values($values) as $i => $v) {
            $cell = Coordinate::stringFromColumnIndex($i + 1) . $row;
            if ($v instanceof ExcelNumber) {
                $sheet->setCellValueExplicit($cell, $v->value, DataType::TYPE_NUMERIC);
            } elseif (is_int($v) || is_float($v)) {
                $sheet->setCellValueExplicit($cell, $v, DataType::TYPE_NUMERIC);
            } else {
                $this->text($sheet, $cell, (string) ($v ?? ''));
            }
        }
    }

    private function excelDate(string $date): ExcelNumber|string
    {
        try {
            $c = CarbonImmutable::createFromFormat('!d/m/Y H:i:s', $date, self::TIMEZONE);
            return $c instanceof CarbonImmutable ? new ExcelNumber(ExcelDate::PHPToExcel($c->toDateTime())) : $date;
        } catch (\Throwable) {
            return $date;
        }
    }

    private function timeFromIso(mixed $date): ExcelNumber|string
    {
        if ($date === null || $date === '') return '';
        try {
            return $this->excelDate(CarbonImmutable::parse((string) $date)->setTimezone(self::TIMEZONE)->format('d/m/Y H:i:s'));
        } catch (\Throwable) {
            return $this->display($date);
        }
    }

    private function text(Worksheet $s, string $cell, string $value): void
    {
        // Always explicit string: avoid spreadsheet formula injection via robot/alarm text.
        $s->setCellValueExplicit($cell, mb_substr($value, 0, 32500), DataType::TYPE_STRING);
    }

    private function display(mixed $value): string
    {
        if ($value === null || $value === '') return '—';
        if (is_bool($value)) return $value ? 'Ya' : 'Tidak';
        if (is_array($value) || is_object($value)) {
            // Human-readable multiline key/value data, not one long JSON string.
            return $this->flattenDetail((array) $value);
        }
        return (string) $value;
    }

    private function flattenDetail(array $detail, string $prefix = '', int $depth = 0): string
    {
        $lines = [];
        foreach ($detail as $key => $value) {
            $label = $prefix . (string) $key;
            if ((is_array($value) || is_object($value)) && $depth < 5) {
                $lines[] = $this->flattenDetail((array) $value, $label . '.', $depth + 1);
            } else {
                $lines[] = $label . ': ' . (
                    $value === null ? '(belum tersedia)' :
                    (is_bool($value) ? ($value ? 'Ya' : 'Tidak') :
                    (is_scalar($value) ? (string) $value : '[data bersarang]'))
                );
            }
        }
        return implode("\n", array_filter($lines, static fn ($s) => $s !== '')) ?: '—';
    }

    private function boolText(mixed $value): string
    {
        return $value === null ? 'Belum terbaca' : ((bool) $value ? 'Ya' : 'Tidak');
    }

    private function axis(mixed $axes, string $axis): mixed
    {
        if (!is_array($axes)) return null;
        return $axes[$axis] ?? $axes[strtolower($axis)] ?? null;
    }

    private function numberOrBlank(mixed $v): int|float|string
    {
        return $v !== null && is_numeric($v) ? (float) $v : '';
    }

    private function verifiedNumber(mixed $v): int|float|string
    {
        return $v !== null && is_numeric($v) ? (float) $v : '';
    }

    private function verifiedHours(mixed $v): float|string
    {
        return $v !== null && is_numeric($v) ? round(((float) $v) / 3600, 3) : '';
    }

    private function maintenanceAction(string $summary): string
    {
        return preg_match('/^Maintenance\s+(.+?)\s+oleh\s+/u', $summary, $m) ? $m[1] : '—';
    }

    private function maintenancePerson(string $summary): string
    {
        return preg_match('/\s+oleh\s+(.+)$/u', $summary, $m) ? $m[1] : '—';
    }

    private function paintStatus(Worksheet $s, string $cell, string $status): void
    {
        $status = strtoupper($status);
        [$bg, $fg] = match (true) {
            (bool) preg_match('/FAILED|ERROR|CRITICAL|OFFLINE|DISCONNECTED|ACTIVE/', $status) => ['FCE7E4', 'A12A27'],
            (bool) preg_match('/WARNING|PARTIAL|STALE|OPEN|UNKNOWN/', $status) => ['FFF0C9', '875A13'],
            (bool) preg_match('/OK|CLEARED|HEALTHY|CONNECTED|COMPLETED/', $status) => ['DDF1E6', '266A49'],
            default => ['E9EEEC', '486258'],
        };
        $s->getStyle($cell)->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
            'font' => ['bold' => true, 'color' => ['rgb' => $fg]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
    }

    private function summary(Worksheet $s, array $entries, array $f, array $counts, array $selected, array $labels, int $limit): void
    {
        $s->setShowGridlines(false);
        $s->getSheetView()->setZoomScale(95);
        foreach (['A'=>28,'B'=>55,'C'=>18,'D'=>22,'E'=>25,'F'=>20] as $col=>$w) {
            $s->getColumnDimension($col)->setWidth($w);
        }
        $s->mergeCells('A1:F2');
        $this->text($s, 'A1', 'RMS YASKAWA  |  LAPORAN HISTORI ROBOT');
        $s->getStyle('A1:F2')->applyFromArray([
            'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'184D42']],
            'font'=>['name'=>'Aptos','size'=>16,'bold'=>true,'color'=>['rgb'=>'FFFFFF']],
            'alignment'=>['vertical'=>Alignment::VERTICAL_CENTER],
        ]);
        $s->getRowDimension(1)->setRowHeight(25);
        $s->getRowDimension(2)->setRowHeight(19);
        $s->mergeCells('A3:F3');
        $this->text($s, 'A3', 'Laporan teknisi · data detail disajikan di setiap tab · seluruh waktu mengikuti WIB');
        $s->getStyle('A3')->getFont()->setItalic(true)->getColor()->setRGB('38675A');
        $s->getRowDimension(3)->setRowHeight(23);

        $selectedCodes = $f['robot_ids'] === [] ? 'Semua robot' :
            Robot::query()->whereIn('id', $f['robot_ids'])->orderBy('robot_code')->pluck('robot_code')->implode(', ');
        $selectedLabels = implode(', ', array_map(static fn ($type) => $labels[$type] ?? $type, $selected));
        $meta = [
            'Tanggal export' => CarbonImmutable::now(self::TIMEZONE)->format('d/m/Y H:i:s') . ' WIB',
            'Periode dari' => str_replace('T', ' ', $f['from']) . ' WIB',
            'Periode sampai' => str_replace('T', ' ', $f['to']) . ' WIB',
            'Robot dipilih' => $selectedCodes,
            'Jenis log dipilih' => $selectedLabels,
            'Total hasil filter' => (string) array_sum($counts),
            'Baris dalam file' => (string) count($entries),
            'Batas web export' => number_format($limit, 0, ',', '.') . ' baris',
        ];
        $i = 5;
        foreach ($meta as $label => $value) {
            $this->text($s, 'A'.$i, $label);
            $s->mergeCells('B'.$i.':F'.$i);
            $this->text($s, 'B'.$i, $value);
            $s->getRowDimension($i)->setRowHeight(26);
            $i++;
        }
        $s->getStyle('A5:A12')->getFont()->setBold(true)->getColor()->setRGB('285E4E');
        $s->getStyle('B5:F12')->getAlignment()->setWrapText(true);
        $s->getStyle('A5:F12')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_HAIR);
        $hasDemo = count(array_filter($entries, static fn ($e) => str_starts_with((string) $e['robot_code'], 'DEMO_'))) > 0;
        $limited = array_sum($counts) > count($entries);
        $s->mergeCells('A14:F14');
        $this->text($s, 'A14', $limited
            ? 'PERHATIAN: Sebagian data tidak diekspor karena batas '. $limit . ' baris. Persempit filter robot / waktu.'
            : 'Seluruh hasil pada rentang filter telah masuk ke file ini.');
        $s->getStyle('A14:F14')->applyFromArray([
            'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$limited?'FFF1D5':'E8F5EE']],
            'font'=>['bold'=>true,'color'=>['rgb'=>$limited?'8A560E':'2D6B4D']],
            'alignment'=>['wrapText'=>true,'vertical'=>Alignment::VERTICAL_CENTER],
        ]);
        $s->getRowDimension(14)->setRowHeight(31);
        $s->mergeCells('A15:F15');
        $this->text($s, 'A15', $hasDemo
            ? 'DATA SIMULASI TERDETEKSI: robot DEMO_* bukan kondisi aktual robot.'
            : 'Status baca OK tidak berarti robot sehat. Verifikasi indikator perawatan terhadap data aktual.');
        $s->getStyle('A15:F15')->getFont()->getColor()->setRGB('A45F28');
        $s->getRowDimension(15)->setRowHeight(28);

        $this->text($s, 'A17', 'RINCIAN PER JENIS LOG');
        $this->text($s, 'D17', 'RINCIAN PER STATUS');
        $s->getStyle('A17:B17')->getFont()->setBold(true)->getColor()->setRGB('24604D');
        $s->getStyle('D17:E17')->getFont()->setBold(true)->getColor()->setRGB('24604D');
        $byType = [];
        $byStatus = [];
        foreach ($entries as $e) {
            $byType[$e['type']] = ($byType[$e['type']] ?? 0) + 1;
            $byStatus[$e['status']] = ($byStatus[$e['status']] ?? 0) + 1;
        }
        $r=18;
        foreach ($selected as $type) {
            $this->text($s, 'A'.$r, $labels[$type] ?? $type);
            $s->setCellValue('B'.$r, $byType[$type] ?? 0);
            $this->text($s, 'C'.$r, 'dari '.($counts[$type] ?? 0));
            $r++;
        }
        $r=18;
        foreach ($byStatus as $status=>$count) {
            $this->text($s, 'D'.$r, (string)$status);
            $s->setCellValue('E'.$r, $count);
            $this->paintStatus($s, 'D'.$r, (string)$status);
            $r++;
        }
        $notesRow = max(30, $r + 2);
        $s->mergeCells('A'.$notesRow.':F'.$notesRow);
        $this->text($s, 'A'.$notesRow, 'Buka tab Operating Time / Alarm Events / Telemetry dan lainnya untuk detail masing-masing record.');
        $s->getStyle('A'.$notesRow)->getFont()->setBold(true)->getColor()->setRGB('2C6B56');
        $s->freezePane('A5');
    }
}

/** Internal numeric value wrapper: differentiates Excel serial dates from text. */
final class ExcelNumber
{
    public function __construct(public readonly float $value) {}
}
