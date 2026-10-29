# Pemetaan JSONL YMConnect → PostgreSQL

Sumber: struktur C++ yang user lampirkan (`telemetryJson()` dan `eventJson()`), bukan API ingestor yang telah dibuat.

| JSONL C++ | Database / Kolom | Catatan |
|---|---|---|
| `robot_id` | `robots.robot_code` → `robots.id` | Wajib registrasi robot sebelum ingest |
| `ip` | `robot_controllers.ip_address`, `robot_connection_events.ip_address` | Jangan digunakan sebagai satu-satunya identitas |
| `type = telemetry` | `robot_telemetry_samples` | Insert sekali per fast polling cycle |
| `timestamp_utc` | `robot_telemetry_samples.sampled_at` | ISO UTC timestamptz |
| `sequence` | `robot_telemetry_samples.sequence` | Reset setelah collector restart |
| `collector_uptime_s` | `robot_telemetry_samples.collector_uptime_seconds` | Uptime proses, bukan operating time robot |
| `position.status.ok` | `robot_telemetry_samples.position_ok` | `false` bukan normal robot |
| `position.status.status_code` | `robot_telemetry_samples.position_status_code` | status SDK |
| `position.status.message` | `robot_telemetry_samples.position_status_message` | Teks status SDK |
| `position.status.sampled_at_utc` | `robot_telemetry_samples.position_sampled_at` | Bisa beda dari timestamp utama |
| `position.axes` | `robot_telemetry_samples.position_pulses` | JSONB SLURBT, PULSE |
| `torque.status.ok` | `robot_telemetry_samples.torque_ok` | Boolean sukses baca |
| `torque.status.status_code` | `robot_telemetry_samples.torque_status_code` | status SDK |
| `torque.status.message` | `robot_telemetry_samples.torque_status_message` | teks SDK |
| `torque.status.sampled_at_utc` | `robot_telemetry_samples.torque_sampled_at` | UTC |
| `torque.axes` | `robot_telemetry_samples.torque_axes` | JSONB SLURBT, satuan SDK RAW |
| `torque.unit` | `robot_telemetry_samples.torque_unit` | nilai `sdk_raw` |
| `active_alarms.status.*` | `robot_alarm_snapshots.read_ok/status_code/status_message/sampled_at` | Simpan hanya bila timestamp pembacaan berubah |
| `active_alarms.sdk_text` | `robot_alarm_snapshots.sdk_text` | Tidak di-parse sembarangan |
| `servo_power_on_time.status.*` | `robot_operating_time_samples` | `time_type=ServoPowerOnTimeRobot` |
| `servo_power_on_time.sdk_raw` | `robot_operating_time_samples.value_raw` | Jangan ubah ke detik sebelum unit verified |
| `type = connection` | `robot_connection_events` | State: connected/offline/reconnecting/stopped |
| `state`, `message`, `timestamp_utc` | `robot_connection_events.state/message/occurred_at` | Log koneksi |

Catatan: Data **status controller, job monitoring, I/O, alarm history terstruktur, PM rules, dan health assessment** tidak dihasilkan oleh kode collector saat ini. Jangan mengarang nilainya. Tabel yang belum punya sumber pembacaan adalah fondasi fitur yang akan dibangun berikutnya.
