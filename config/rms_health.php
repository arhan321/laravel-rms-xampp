<?php
return [
    'fresh_seconds' => (int) env('RMS_HEALTH_FRESH_SECONDS', 30),
    'alarm_fresh_seconds' => (int) env('RMS_ALARM_FRESH_SECONDS', 60),
    'minimum_baseline_samples' => (int) env('RMS_BASELINE_MIN_SAMPLES', 40),
    'baseline_lookback_days' => (int) env('RMS_BASELINE_LOOKBACK_DAYS', 30),
    'anomaly_warning_z' => (float) env('RMS_ANOMALY_WARNING_Z', 3.5),
    'anomaly_critical_z' => (float) env('RMS_ANOMALY_CRITICAL_Z', 5.0),
    'anomaly_recovery_z' => (float) env('RMS_ANOMALY_RECOVERY_Z', 2.0),
    'max_prediction_relative_mae' => (float) env('RMS_PREDICTION_MAX_RELATIVE_MAE', 0.15),
    'prediction_min_measurements' => (int) env('RMS_PREDICTION_MIN_MEASUREMENTS', 12),
    'prediction_min_span_days' => (int) env('RMS_PREDICTION_MIN_SPAN_DAYS', 30),
];
