<datalist id="rms-line-options">
  @foreach(($groupOptions ?? collect()) as $lineOption)<option value="{{ $lineOption->name }}"></option>@endforeach
</datalist>
