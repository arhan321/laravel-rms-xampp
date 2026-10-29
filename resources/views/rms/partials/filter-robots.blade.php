<label class="field">Robot
  <select class="input" name="robot_id"><option value="">Semua robot</option>
    @foreach($robots as $r)<option value="{{ $r->id }}" @selected((string) request('robot_id') === (string) $r->id)>{{ $r->robot_code }} — {{ $r->name }}</option>@endforeach
  </select>
</label>
