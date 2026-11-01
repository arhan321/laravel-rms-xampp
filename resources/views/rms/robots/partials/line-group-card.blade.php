<a class="rms-line-card {{ $group['pilot'] ? 'rms-line-card-pilot' : '' }}" data-rms-line-summary="{{ $group['key'] }}" href="{{ route('rms.robots.index', ['group' => $group['key']]) }}" aria-label="Buka robot {{ $group['label'] }}">
  <div class="rms-line-card-top"><span class="rms-line-category">{{ $group['area_label'] ?: 'GRUP ROBOT' }}</span>@if($group['pilot'])<span class="rms-line-pilot">PILOT</span>@else<span class="rms-line-card-arrow" aria-hidden="true">↗</span>@endif</div>
  <h3>{{ $group['label'] }}</h3>
  @if($group['description'])<p class="rms-line-card-description">{{ $group['description'] }}</p>@endif
  <div class="rms-line-scene"><img class="rms-yaskawa-image" src="{{ asset('rms/images/yaskawa-robot.png') }}" alt="" width="300" height="130" loading="lazy"></div>
  <div class="rms-line-card-total"><strong data-rms-group-stat="registered">{{ $group['registered'] }}</strong><span>robot<br>terdaftar</span>@if($group['planned_robots'] !== null)<div class="rms-line-card-plan"><b>{{ $group['planned_robots'] }}</b><span>rencana</span></div>@endif</div>
  <div class="rms-line-card-metrics"><span><i class="rms-line-dot" aria-hidden="true"></i><b data-rms-group-stat="fresh">{{ $group['fresh'] }}</b> fresh</span><span title="Robot dengan health WARNING atau UPNORMAL"><b data-rms-group-stat="attention">{{ $group['attention'] }}</b> perlu perhatian</span></div>
  <div class="rms-line-card-footer"><span data-rms-group-status>{{ $group['registered'] === 0 ? 'Belum ada robot terdaftar' : ($group['fresh'] > 0 ? 'Data monitoring tersedia' : 'Menunggu data fresh') }}</span><strong>Lihat robot →</strong></div>
</a>
