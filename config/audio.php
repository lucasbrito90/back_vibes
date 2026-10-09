<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Audio processing queue
    |--------------------------------------------------------------------------
    | Dedicated connection (same `jobs` table, longer retry_after) and queue name so the push /
    | smart-home worker never reserves FFmpeg jobs and the short default retry_after never
    | re-delivers a job that is still transcoding.
    */
    'queue' => [
        'connection' => env('AUDIO_QUEUE_CONNECTION', 'database_audio'),
        'name' => env('AUDIO_QUEUE_NAME', 'audio'),
        'tries' => (int) env('AUDIO_JOB_TRIES', 3),
        'backoff' => [60, 300],
        // Hard job timeout (needs pcntl in the worker). retry_after of the connection must exceed it.
        'timeout' => (int) env('AUDIO_JOB_TIMEOUT', 600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Recovery
    |--------------------------------------------------------------------------
    */
    'recovery' => [
        // A `processing` revision whose heartbeat is older than this has lost its worker. Must stay above the
        // job timeout (heartbeats are written between steps) and below the connection retry_after.
        'stale_after_seconds' => (int) env('AUDIO_STALE_AFTER_SECONDS', 720),
        // A `queued` revision never handed to the queue (crash between commit and dispatch).
        'undispatched_after_seconds' => (int) env('AUDIO_UNDISPATCHED_AFTER_SECONDS', 120),
        // A `queued` revision whose job vanished from the queue is handed over again after this long.
        'queued_redispatch_after_seconds' => (int) env('AUDIO_QUEUED_REDISPATCH_AFTER_SECONDS', 1800),
        // Give up (mark failed) after this many processing attempts in total.
        'max_attempts' => (int) env('AUDIO_MAX_ATTEMPTS', 5),
        // When true, queue workers run the recovery sweep between jobs (set only on the audio worker).
        'on_worker_loop' => (bool) env('AUDIO_RECOVERY_ON_WORKER_LOOP', false),
        'worker_loop_interval_seconds' => (int) env('AUDIO_RECOVERY_INTERVAL_SECONDS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | FFmpeg
    |--------------------------------------------------------------------------
    */
    'ffmpeg' => [
        'binary' => env('FFMPEG_BINARY', 'ffmpeg'),
        'ffprobe_binary' => env('FFPROBE_BINARY', 'ffprobe'),
        'probe_timeout' => (int) env('AUDIO_PROBE_TIMEOUT', 60),
        'transcode_timeout' => (int) env('AUDIO_TRANSCODE_TIMEOUT', 480),
        'temp_directory' => env('AUDIO_TEMP_DIRECTORY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Distribution profile — PROVISIONAL
    |--------------------------------------------------------------------------
    | The final codec/container/bitrate is NOT decided: it must be chosen after evaluating
    | representative samples (quality, size, Media3 compatibility, gapless looping, future iOS).
    | These values only make the pipeline runnable end to end. Change via env, never in code paths.
    */
    'profile' => [
        'name' => env('AUDIO_PROFILE_NAME', 'provisional-aac-128k'),
        'extension' => env('AUDIO_PROFILE_EXTENSION', 'm4a'),
        'mime' => env('AUDIO_PROFILE_MIME', 'audio/mp4'),
        'codec' => env('AUDIO_PROFILE_CODEC', 'aac'),
        'bitrate' => env('AUDIO_PROFILE_BITRATE', '128k'),
        // Extra encoder args appended after -c:a/-b:a (space separated).
        'extra_args' => env('AUDIO_PROFILE_EXTRA_ARGS', '-movflags +faststart'),
    ],

    'validation' => [
        'min_duration_ms' => (int) env('AUDIO_MIN_DURATION_MS', 100),
        'max_duration_seconds' => (int) env('AUDIO_MAX_DURATION_SECONDS', 3600),
        // Output duration must match the source within max(absolute, relative * source).
        'duration_tolerance_ms' => (int) env('AUDIO_DURATION_TOLERANCE_MS', 1500),
        'duration_tolerance_ratio' => (float) env('AUDIO_DURATION_TOLERANCE_RATIO', 0.02),
    ],

];
