<?php

declare(strict_types=1);

namespace App\Services\Rms;

use App\Models\RobotAlarmFollowUp;
use FPDF;

final class AlarmFollowUpPdf
{
    private const WIDTH = 190;
    private const INK = [27, 44, 40];
    private const GREEN = [45, 102, 79];
    private const GRID = [217, 227, 221];
    private const RED = [155, 49, 39];
    private const PALETTE = [[32,87,157], [157,78,32], [34,109,77], [145,55,85], [99,68,157], [108,105,31]];

    public function render(RobotAlarmFollowUp $report): string
    {
        require_once app_path('Support/FuiPdf/fpdf.php');
        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->AddFont('RMS', '', 'RMSReportSans-Regular.json');
        $pdf->AddFont('RMS', 'B', 'RMSReportSans-Bold.json');
        $pdf->AddFont('RMS', 'I', 'RMSReportSans-Italic.json');
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(false);
        $pdf->AliasNbPages();
        $pdf->SetTitle($report->number().' - Follow Up Instruction', true);
        $pdf->SetAuthor('PT Astra Daihatsu Motor - RMS');
        $pdf->SetCreator('Robot Management System');
        $case = $report->evidence;

        $this->header($pdf, $report, 'DATA TEKNIS / FAULT CODE');
        $this->fault($pdf, $case);
        $this->header($pdf, $report, 'DATA TEKNIS / TREN ROBOT');
        $this->trends($pdf, $case['trend']);
        $this->header($pdf, $report, 'TINDAK LANJUT / MEKANIK DAN PERSETUJUAN');
        $this->followUp($pdf, $report);

        return $pdf->Output('S');
    }

    private function header(FPDF $pdf, RobotAlarmFollowUp $report, string $section): void
    {
        $pdf->AddPage();
        $pdf->SetLineWidth(.25);
        $pdf->SetDrawColor(...self::GRID);
        $pdf->SetTextColor(...self::INK);
        $logo = public_path('rms/images/daihatsu-sahabatku.png');
        if (is_file($logo)) {
            $pdf->Image($logo, 11, 11, 25);
        }
        $pdf->SetXY(40, 11);
        $pdf->SetFont('RMS', 'B', 11);
        $pdf->Cell(159, 5, 'PT ASTRA DAIHATSU MOTOR');
        $pdf->SetXY(40, 17);
        $pdf->SetFont('RMS', 'I', 18);
        $pdf->Cell(159, 7, 'Follow Up Instruction');
        $pdf->SetXY(40, 25);
        $pdf->SetFont('RMS', '', 9);
        $pdf->Cell(159, 4, 'Robot Management System  /  Alarm Center');
        $pdf->SetXY(10, 34);
        $this->block($pdf, $section);

        $robot = $report->evidence['robot'];
        $alarm = $report->evidence['alarm'];
        $pairs = [
            ['No. laporan', $report->number().' / v'.$report->version, 'Kode robot', $robot['code']],
            ['Tanggal laporan', $report->created_at->timezone('Asia/Jakarta')->format('d/m/Y H:i').' WIB', 'Model / serial robot', $this->joined([$robot['model'], $robot['serial']])],
            ['Status dokumen', $this->status($report), 'Controller / firmware', $this->joined([$robot['controller_model'], $robot['firmware']])],
            ['Line / Station', $this->joined([$robot['line'], $robot['station']]), 'Control group', $robot['group']],
            ['Fault / Subcode', $this->joined([$alarm['alarm_code'], $alarm['subcode']]), 'Waktu RMS', AlarmFollowUpEvidence::wib($alarm['observed_at'])],
        ];
        foreach ($pairs as [$label, $value, $otherLabel, $other]) {
            $pdf->SetFont('RMS', 'B', 8);
            $pdf->SetFillColor(242, 247, 243);
            $pdf->Cell(33, 6, $this->text($label), 1, 0, 'L', true);
            $pdf->SetFont('RMS', '', 8);
            $pdf->Cell(62, 6, $this->fit($pdf, $value, 60), 1);
            $pdf->SetFont('RMS', 'B', 8);
            $pdf->Cell(33, 6, $this->text($otherLabel), 1, 0, 'L', true);
            $pdf->SetFont('RMS', '', 8);
            $pdf->Cell(62, 6, $this->fit($pdf, $other, 60), 1, 1);
        }
        $pdf->Ln(4);
        $y = $pdf->GetY();
        $pdf->SetXY(10, 283);
        $pdf->SetDrawColor(...self::GRID);
        $pdf->Line(10, 282, 200, 282);
        $pdf->SetFont('RMS', '', 8);
        $pdf->Cell(135, 6, $report->number().'  /  v'.$report->version);
        $pdf->Cell(55, 6, 'Halaman '.$pdf->PageNo().' / {nb}', 0, 0, 'R');
        $pdf->SetXY(10, $y);
    }

    private function fault(FPDF $pdf, array $case): void
    {
        $alarm = $case['alarm'];
        $robot = $case['robot'];
        $this->note($pdf, 'Alarm: '.($alarm['alarm_name'] ?: '-').'  |  Sumber: '.($alarm['kind'] === 'history' ? 'Alarm History' : 'Alarm Event').' #'.$alarm['record']);
        $this->note($pdf, 'Waktu controller (raw): '.($alarm['controller_time_raw'] ?? '-'));
        $this->note($pdf, 'Kode controller: '.($robot['controller_code'] ?? '-').'  |  Nama robot: '.($robot['name'] ?? '-'));
        $this->note($pdf, 'Jam servo: '.$robot['servo_hours']['text'].'  |  Jam controller: '.$robot['controller_hours']['text']);
        if ($robot['servo_hours']['sampled_wib']) {
            $this->note($pdf, $robot['servo_hours']['caption']);
        }
        $pdf->Ln(2);
        $faults = $case['pareto'];
        $this->block($pdf, 'PARETO ALARM - '.(int) $faults['total'].' REKAMAN HISTORI UNIK');
        if ($faults['rows'] === []) {
            $this->note($pdf, 'Riwayat alarm robot belum tersedia.');
        } else {
            $this->pareto($pdf, $faults['rows'], $alarm);
            $this->row($pdf, [28, 88, 20, 25, 29], ['Kode / Sub', 'Nama alarm', 'Jumlah', 'Persen', 'Kumulatif'], true);
            foreach ($faults['rows'] as $row) {
                $this->row($pdf, [28, 88, 20, 25, 29], [
                    $row['label'], $row['name'], $row['count'], $row['percent'].'%', $row['cumulative'].'%',
                ]);
            }
        }
        $pdf->Ln(3);
        $this->block($pdf, 'ALARM HISTORY - HINGGA 8 REKAMAN TERAKHIR');
        $this->row($pdf, [22, 18, 60, 44, 46], ['Kode / Sub', 'Kategori', 'Nama alarm', 'Waktu controller (raw)', 'Pertama terlihat RMS'], true);
        foreach ($case['history'] as $row) {
            $this->row($pdf, [22, 18, 60, 44, 46], [
                $row['subcode'] !== null ? $row['code'].' / '.$row['subcode'] : $row['code'], $row['category'],
                $row['name'], $row['raw'] ?? '-', str_replace(' WIB', '', $row['seen']),
            ]);
        }
        if ($case['history'] === []) {
            $this->note($pdf, 'Belum ada rekaman histori controller untuk robot ini.');
        }
        $this->note($pdf, 'Cakupan: seluruh histori tersimpan untuk robot ini. Data diambil '.AlarmFollowUpEvidence::wib($case['captured_at'] ?? null).'.');
    }

    private function pareto(FPDF $pdf, array $rows, array $alarm): void
    {
        $left = 19;
        $right = 190;
        $top = $pdf->GetY() + 7;
        $bottom = $top + 33;
        $step = ($right - $left) / count($rows);
        $maximum = max(array_column($rows, 'count'));
        $pdf->SetFont('RMS', '', 6.8);
        $pdf->SetDrawColor(...self::GRID);
        $stepCount = max(1, (int) ceil($maximum / 4));
        $scale = $stepCount * 4;
        for ($index = 0; $index <= 4; $index++) {
            $y = $bottom - ($bottom - $top) * $index / 4;
            $pdf->Line($left, $y, $right, $y);
            $pdf->SetXY(10, $y - 2);
            $pdf->Cell(8, 4, (string) ($stepCount * $index), 0, 0, 'R');
            $pdf->SetXY($right + 1, $y - 2);
            $pdf->Cell(9, 4, ($index * 25).'%', 0, 0, 'R');
        }
        $points = [];
        foreach ($rows as $index => $row) {
            $x = $left + ($index + .5) * $step;
            $height = ($bottom - $top) * $row['count'] / $scale;
            $marked = ! $row['other'] && $row['code'] === $alarm['alarm_code'] && $row['subcode'] === $alarm['subcode'];
            $pdf->SetFillColor(...($marked ? self::RED : self::GREEN));
            $pdf->Rect($x - $step * .27, $bottom - $height, $step * .54, $height, 'F');
            $points[] = [$x, $bottom - ($bottom - $top) * $row['cumulative'] / 100];
            $pdf->SetXY($x - $step / 2, $bottom + 1);
            $pdf->Cell($step, 4, $this->fit($pdf, $row['label'], $step - 1), 0, 0, 'C');
        }
        $pdf->SetDrawColor(41, 67, 139);
        $pdf->SetFillColor(41, 67, 139);
        $last = null;
        foreach ($points as [$x, $y]) {
            if ($last !== null) {
                $pdf->Line($last[0], $last[1], $x, $y);
            }
            $pdf->Rect($x - .6, $y - .6, 1.2, 1.2, 'F');
            $last = [$x, $y];
        }
        $pdf->SetXY(10, $bottom + 6);
        $this->note($pdf, 'Batang: jumlah rekaman   |   Garis biru: kumulatif   |   Merah: kode alarm FUI');
    }

    private function trends(FPDF $pdf, array $trend): void
    {
        $this->note($pdf, $trend['from'].' - '.$trend['until']);
        $this->note($pdf, 'Sampel tersimpan dari robot: satu pembacaan terakhir per menit. Garis putus merah menunjukkan waktu alarm RMS.');
        $this->note($pdf, 'Jendela mencakup 15 menit sebelum dan sesudah alarm. Bagian kosong menunjukkan sampel tidak tersedia atau tidak valid.');
        $pdf->Ln(4);
        $this->trendChart($pdf, 'SERVO MONITOR - TORQUE', $trend['torque'], $trend['torque_unit'] ?? 'Satuan belum tersedia');
        $pdf->Ln(5);
        $this->trendChart($pdf, 'SERVO MONITOR - POSISI PULSE', $trend['position'], 'pulse');
    }

    private function trendChart(FPDF $pdf, string $title, array $series, string $unit): void
    {
        $this->block($pdf, $title.'  /  '.$unit);
        $minimum = INF;
        $maximum = -INF;
        foreach ($series as $samples) {
            foreach ($samples as $sample) {
                if ($sample !== null) {
                    $minimum = min($minimum, $sample['value']);
                    $maximum = max($maximum, $sample['value']);
                }
            }
        }
        $origin = $pdf->GetY();
        if (! is_finite($minimum)) {
            $pdf->SetDrawColor(...self::GRID);
            $pdf->Rect(10, $origin, self::WIDTH, 67);
            $pdf->SetXY(10, $origin + 27);
            $pdf->SetFont('RMS', '', 10);
            $pdf->Cell(self::WIDTH, 6, 'Belum ada sampel valid pada jendela waktu ini.', 0, 0, 'C');
            $pdf->SetXY(10, $origin + 67);
            return;
        }
        $spread = $maximum - $minimum;
        $pad = $spread > 0 ? $spread * .10 : max(abs($maximum) * .10, 1);
        $range = ($maximum + $pad) - ($minimum - $pad);
        $floor = $minimum - $pad;
        $left = 36;
        $right = 193;
        $top = $origin + 7;
        $bottom = $top + 43;
        $pdf->SetFont('RMS', '', 7);
        $pdf->SetDrawColor(...self::GRID);
        for ($i = 0; $i <= 4; $i++) {
            $y = $bottom - ($bottom - $top) * $i / 4;
            $pdf->Line($left, $y, $right, $y);
            $pdf->SetXY(11, $y - 2);
            $pdf->Cell(23, 4, sprintf('%.6g', $floor + $range * $i / 4), 0, 0, 'R');
        }
        $alarmX = ($right + $left) / 2;
        $pdf->SetDrawColor(...self::RED);
        for ($dash = $top; $dash < $bottom; $dash += 4) {
            $pdf->Line($alarmX, $dash, $alarmX, min($dash + 2, $bottom));
        }
        foreach ([-15, -10, -5, 0, 5, 10, 15] as $index => $minute) {
            $pdf->SetXY($left + ($right - $left) * $index / 6 - 7, $bottom + 1);
            $pdf->Cell(14, 4, $minute === 0 ? 'Alarm' : (string) $minute, 0, 0, 'C');
        }
        $pdf->SetXY($left, $bottom + 7);
        $pdf->Cell($right - $left, 4, 'Menit terhadap alarm', 0, 0, 'C');

        foreach (array_keys($series) as $index => $axis) {
            $color = self::PALETTE[$index];
            $pdf->SetDrawColor(...$color);
            $pdf->SetFillColor(...$color);
            $last = null;
            foreach ($series[$axis] as $sample) {
                if ($sample === null) {
                    $last = null;
                    continue;
                }
                $x = $left + ($right - $left) * $sample['minute'] / 30;
                $y = $bottom - ($bottom - $top) * ($sample['value'] - $floor) / $range;
                if ($last !== null) {
                    $pdf->Line($last[0], $last[1], $x, $y);
                }
                $pdf->Rect($x - .45, $y - .45, .9, .9, 'F');
                $last = [$x, $y];
            }
            $pdf->SetXY($left + $index * 22, $bottom + 13);
            $pdf->SetTextColor(...$color);
            $pdf->Cell(20, 4, 'Axis '.$axis, 0, 0, 'C');
        }
        $pdf->SetTextColor(...self::INK);
        $pdf->SetXY(10, $bottom + 19);
    }

    private function followUp(FPDF $pdf, RobotAlarmFollowUp $report): void
    {
        $case = $report->evidence;
        $work = $report->work ?? [];
        $prior = $case['previous'];
        $this->note($pdf, 'Mekanik: '.($work['mechanic_name'] ?? '-').'  |  Tanggal: '.($work['follow_date'] ?? '-').'  |  Jam operasi: '.($work['follow_hours'] ?? '-'));
        $this->note($pdf, 'Pengisi: '.($work['editor'] ?? '-').'  |  Status pekerjaan: '.(RobotAlarmFollowUp::RESULTS[$work['result'] ?? ''] ?? '-'));
        $this->textBlock($pdf, $report, 'KONDISI DAN REKOMENDASI SEBELUMNYA', $prior
            ? $prior['number']."\n".$this->joined([$prior['work']['current_condition'] ?? null, $prior['work']['recommendation'] ?? null], "\n")
            : 'Belum ada FUI terdahulu yang disetujui untuk robot, kode, dan subcode yang sama.');
        if ($prior) {
            $this->textBlock($pdf, $report, 'TINDAK LANJUT MEKANIK SEBELUMNYA', $prior['work']['mechanic_action'] ?? '-');
        }
        foreach ([
            'current_condition' => 'KONDISI SAAT INI', 'recommendation' => 'REKOMENDASI SAAT INI',
            'mechanic_findings' => 'HASIL PEMERIKSAAN MEKANIK', 'mechanic_action' => 'FOLLOW UP OLEH MEKANIK',
            'foreman_alternative' => 'SOLUSI ALTERNATIF FOREMAN',
        ] as $key => $label) {
            $this->textBlock($pdf, $report, $label, $work[$key] ?? '-');
        }
        foreach (['yaskawa_manual' => 'REFERENSI YASKAWA', 'field_experience' => 'PENGALAMAN TIM JALUR'] as $key => $title) {
            $entries = $case['references'][$key];
            if ($entries === []) {
                $this->textBlock($pdf, $report, $title, 'Belum ada panduan yang cocok dan aktif di RMS.');
            }
            foreach ($entries as $guide) {
                $details = [$guide['title'].' (versi '.$guide['version'].')'];
                foreach ([
                    'source_reference' => 'Rujukan', 'source_revision' => 'Revisi sumber', 'work_area' => 'Area', 'reported_by' => 'Pengisi',
                    'alarm_content' => 'Isi alarm / gejala', 'cause' => 'Temuan / penyebab',
                    'measure' => 'Panduan tindakan', 'result_notes' => 'Catatan hasil',
                ] as $field => $label) {
                    if (filled($guide[$field] ?? null)) {
                        $details[] = $label.': '.$guide[$field];
                    }
                }
                $this->textBlock($pdf, $report, $title, implode("\n", $details));
            }
        }
        $this->signatures($pdf, $report);
    }

    private function signatures(FPDF $pdf, RobotAlarmFollowUp $report): void
    {
        if ($pdf->GetY() + 46 > 277) {
            $this->header($pdf, $report, 'TINDAK LANJUT / PERSETUJUAN');
        }
        $this->block($pdf, 'TANDA TANGAN INTERNAL RMS');
        $y = $pdf->GetY();
        $width = self::WIDTH / 3;
        $index = 0;
        foreach (['validated' => 'Validate By', 'approved' => 'Approved By', 'analyzed' => 'Analyzed By'] as $stage => $title) {
            $signature = $report->signatures[$stage] ?? null;
            $x = 10 + $width * $index++;
            $pdf->SetDrawColor(...self::GRID);
            $pdf->Rect($x, $y, $width, 34);
            $pdf->SetXY($x, $y + 1);
            $pdf->SetFont('RMS', 'B', 8);
            $pdf->Cell($width, 4, $title.' / '.RobotAlarmFollowUp::STAGES[$stage], 0, 0, 'C');
            if ($signature) {
                $pdf->SetDrawColor(...self::INK);
                foreach ($signature['strokes'] as $stroke) {
                    $last = null;
                    foreach ($stroke as [$sx, $sy]) {
                        $point = [$x + 8 + $sx * ($width - 16) / 1000, $y + 7 + $sy * 14 / 1000];
                        if ($last !== null) {
                            $pdf->Line($last[0], $last[1], $point[0], $point[1]);
                        }
                        $last = $point;
                    }
                }
            }
            $pdf->SetXY($x, $y + 22);
            $pdf->SetFont('RMS', '', 8);
            $pdf->Cell($width, 4, $this->fit($pdf, $signature['name'] ?? 'Menunggu tanda tangan', $width - 3), 0, 0, 'C');
            if ($signature) {
                $pdf->SetXY($x, $y + 27);
                $pdf->Cell($width, 4, AlarmFollowUpEvidence::wib($signature['at']), 0, 0, 'C');
            }
        }
        $pdf->SetXY(10, $y + 38);
    }

    private function textBlock(FPDF $pdf, RobotAlarmFollowUp $report, string $title, string $text): void
    {
        if ($pdf->GetY() + 16 > 277) {
            $this->header($pdf, $report, 'TINDAK LANJUT / LANJUTAN');
        }
        $this->block($pdf, $title);
        $pdf->SetFont('RMS', '', 9);
        $raw = $this->text($text);
        $lines = [];
        foreach (explode("\n", $raw) as $paragraph) {
            $line = '';
            foreach (str_split($paragraph) as $character) {
                if ($pdf->GetStringWidth($line.$character) > self::WIDTH - 4) {
                    $split = strrpos($line, ' ');
                    $lines[] = $split !== false && $split > 0 ? substr($line, 0, $split) : $line;
                    $line = $split !== false && $split > 0 ? substr($line, $split + 1) : '';
                }
                $line .= $character;
            }
            $lines[] = $line;
        }
        foreach ($lines as $line) {
            if ($pdf->GetY() + 4 > 277) {
                $this->header($pdf, $report, 'TINDAK LANJUT / LANJUTAN');
                $this->block($pdf, $title.' (LANJUTAN)');
                $pdf->SetFont('RMS', '', 9);
            }
            $pdf->Cell(self::WIDTH, 4, $line, 0, 1);
        }
        $pdf->Ln(2);
    }

    private function status(RobotAlarmFollowUp $report): string
    {
        return $report->status === 'approved' ? 'FINAL / Disetujui' : 'PRATINJAU / '.RobotAlarmFollowUp::STATUS[$report->status];
    }

    private function block(FPDF $pdf, string $title): void
    {
        $pdf->SetFillColor(...self::GREEN);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('RMS', 'B', 9);
        $pdf->Cell(self::WIDTH, 6, $this->text($title), 0, 1, 'L', true);
        $pdf->SetTextColor(...self::INK);
    }

    private function note(FPDF $pdf, string $text): void
    {
        $pdf->SetFont('RMS', '', 8);
        $pdf->Cell(self::WIDTH, 4.7, $this->fit($pdf, $text, self::WIDTH - 3), 0, 1);
    }

    private function row(FPDF $pdf, array $widths, array $cells, bool $heading = false): void
    {
        $pdf->SetDrawColor(...self::GRID);
        $pdf->SetFillColor(242, 247, 243);
        $pdf->SetFont('RMS', $heading ? 'B' : '', 7.1);
        foreach ($cells as $index => $cell) {
            $pdf->Cell($widths[$index], 4.65, $this->fit($pdf, (string) $cell, $widths[$index] - 2.6), 1, 0, 'L', $heading);
        }
        $pdf->Ln(4.65);
    }

    private function joined(array $parts, string $glue = ' / '): string
    {
        return implode($glue, array_map(fn ($part) => filled($part) ? $part : '-', $parts));
    }

    private function fit(FPDF $pdf, ?string $value, float $width): string
    {
        $text = $this->text(str_replace(["\r", "\n"], ' ', filled($value) ? $value : '-'));
        if ($pdf->GetStringWidth($text) <= $width) {
            return $text;
        }
        while ($text !== '' && $pdf->GetStringWidth($text.'...') > $width) {
            $text = substr($text, 0, -1);
        }

        return $text.'...';
    }

    private function text(string $value): string
    {
        return mb_convert_encoding(str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $value), 'Windows-1252', 'UTF-8');
    }
}
