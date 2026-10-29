<div class="rms-identity-facts">
  <div><span>Serial Number</span><strong class="mono" data-rms-identity="serial_number">{{ $robot->serial_number ?: 'Belum diisi' }}</strong></div>
  <div><span>Type</span><strong class="mono" data-rms-identity="robot_type">{{ $robot->robot_type ?: 'Belum diisi' }}</strong></div>
  <div><span>Date (nameplate)</span><strong data-rms-identity="nameplate_date">{{ $robot->nameplate_date ? str_replace('-', '.', $robot->nameplate_date) : 'Belum diisi' }}</strong></div>
</div>
