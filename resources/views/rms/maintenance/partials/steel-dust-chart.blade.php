@php
    $colors = ['#295ea0', '#ae5324', '#28704e', '#8e3f68', '#644da2', '#707025'];
    $warningColor = '#ae3827';
    $startOfChart = \Carbon\CarbonImmutable::parse($chartData['start'], 'UTC');
    $chartDays = $startOfChart->diffInDays(\Carbon\CarbonImmutable::parse($chartData['end'], 'UTC'));
    $maximum = 0.0;
    $hasWarning = false;
    foreach ($chartData['points'] as $point) {
        foreach ($point['readings'] as $reading) {
            $maximum = max($maximum, (float) ($reading['value'] ?? 0));
            $hasWarning = $hasWarning || $reading['warning'];
        }
    }
    $ceiling = $maximum > 0 ? $maximum * 1.1 : 1;
    $scaleLabels = [];
    foreach (range(0, 4) as $tick) {
        $scaleLabels[] = rtrim(rtrim(number_format($ceiling * $tick / 4, 8, ',', '.'), '0'), ',');
    }
    $left = max(42, max(array_map('strlen', $scaleLabels)) * 8 + 14);
    $right = 878;
    $top = 28;
    $bottom = 236;
    $x = static fn (float $day) => round($left + $day / $chartDays * ($right - $left), 3);
    $y = static fn (float $value) => round($bottom - $value / $ceiling * ($bottom - $top), 3);

    $series = [];
    foreach ($chartData['axes'] as $index => $axis) {
        $path = [];
        $markers = [];
        $connect = false;
        foreach ($chartData['points'] as $point) {
            $reading = $point['readings'][$axis];
            if ($reading['value'] === null) {
                $connect = false;
                continue;
            }
            $date = \Carbon\CarbonImmutable::parse($point['date'], 'UTC');
            $pointX = $x($startOfChart->diffInDays($date));
            $pointY = $y((float) $reading['value']);
            $path[] = ($connect ? 'L ' : 'M ').$pointX.' '.$pointY;
            $markers[] = [
                'x' => $pointX,
                'y' => $pointY,
                'date' => $date->format('d/m/Y'),
                'value' => $reading['value'],
                'warning' => $reading['warning'],
            ];
            $connect = true;
        }
        $series[] = ['axis' => $axis, 'color' => $colors[$index], 'path' => implode(' ', $path), 'markers' => $markers];
    }
@endphp

<div class="steel-chart">
  <div class="steel-table-wrap">
    <svg viewBox="0 0 900 270" role="img" aria-labelledby="steel-chart-title steel-chart-desc"
         xmlns="http://www.w3.org/2000/svg" font-family="system-ui, sans-serif" font-size="14">
      <title id="steel-chart-title">Grafik Steel Dust enam axis dalam satuan {{ $chartData['unit'] }}</title>
      <desc id="steel-chart-desc">Titik menunjukkan hasil pemeriksaan per tanggal. Cincin merah menandai warning. Nilai lengkap tersedia di riwayat.</desc>
      <g fill="#41554a">
        <text x="{{ $left }}" y="16">{{ $chartData['unit'] }}</text>
        @foreach($scaleLabels as $tick => $label)
          @php($gridY = $y($ceiling * $tick / 4))
          <line x1="{{ $left }}" x2="{{ $right }}" y1="{{ $gridY }}" y2="{{ $gridY }}" stroke="#dce6df" />
          <text x="{{ $left - 8 }}" y="{{ $gridY + 5 }}" text-anchor="end">{{ $label }}</text>
        @endforeach
        @foreach(range(0, 5) as $tick)
          @php($day = (int) round($chartDays * $tick / 5))
          <text x="{{ $x($day) }}" y="{{ $bottom + 22 }}" text-anchor="{{ $tick === 0 ? 'start' : ($tick === 5 ? 'end' : 'middle') }}">{{ $startOfChart->addDays($day)->locale('id')->translatedFormat('d M') }}</text>
        @endforeach
      </g>
      @foreach($series as $line)
        @if($line['markers'] !== [])
          <g data-steel-axis="{{ $line['axis'] }}">
            <path d="{{ $line['path'] }}" fill="none" stroke="{{ $line['color'] }}" stroke-width="2" />
            @foreach($line['markers'] as $marker)
              <circle cx="{{ $marker['x'] }}" cy="{{ $marker['y'] }}" r="3.5" fill="{{ $line['color'] }}">
                <title>Axis {{ $line['axis'] }} · {{ $marker['date'] }} · {{ $marker['value'] }} {{ $chartData['unit'] }}{{ $marker['warning'] ? ' · Warning' : '' }}</title>
              </circle>
            @endforeach
          </g>
        @endif
      @endforeach
      @foreach($series as $line)
        @foreach($line['markers'] as $marker)
          @if($marker['warning'])
            <circle cx="{{ $marker['x'] }}" cy="{{ $marker['y'] }}" r="6.5" fill="none" stroke="{{ $warningColor }}" stroke-width="2" pointer-events="none" aria-hidden="true" />
          @endif
        @endforeach
      @endforeach
    </svg>
  </div>
  <div class="steel-legend" aria-label="Legenda grafik">
    @foreach($series as $line)
      <span style="--series-color: {{ $line['color'] }}">Axis {{ $line['axis'] }}</span>
    @endforeach
    @if($hasWarning)
      <span class="steel-legend-warning" style="--series-color: {{ $warningColor }}">Warning (cincin merah)</span>
    @endif
  </div>
</div>
