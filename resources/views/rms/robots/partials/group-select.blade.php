@php($chosenGroupId = old('robot_group_id', $currentGroupId ?? null))
<input type="hidden" name="original_robot_group_id" value="{{ old('original_robot_group_id', $currentGroupId ?? 0) }}">
<label class="field"><span>Grup robot</span>
  <select class="input" name="robot_group_id" @disabled(!$groupWritesEnabled)>
    <option value="" @selected(blank($chosenGroupId))>Belum dikelompokkan</option>
    @foreach($groupOptions as $option)<option value="{{ $option->id }}" @selected((int) $chosenGroupId === $option->id)>{{ $option->name }}{{ $option->area_label ? ' · '.$option->area_label : '' }}</option>@endforeach
  </select>
  @unless($groupWritesEnabled)<input type="hidden" name="robot_group_id" value="{{ $chosenGroupId }}">@endunless
  <small>Menentukan kartu grup pada Robot Monitor. Grup dapat dibuat melalui <a href="{{ route('rms.robot-groups.index') }}">Kelola grup</a>.</small>
</label>
