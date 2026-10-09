<?php

namespace App\Providers;

use App\Services\Audio\AudioTranscoding;
use App\Services\Audio\FfmpegAudioTranscoder;
use App\Services\Audio\SoundAudioRecovery;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AudioTranscoding::class, FfmpegAudioTranscoder::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configureAudioRecovery();
    }

    /**
     * The dedicated audio worker sweeps for lost/stuck revisions between jobs, so no extra process or cron is
     * needed. Enabled only where AUDIO_RECOVERY_ON_WORKER_LOOP=true (the audio worker component).
     */
    private function configureAudioRecovery(): void
    {
        if (! config('audio.recovery.on_worker_loop')) {
            return;
        }

        $lastRun = 0;

        Queue::looping(function () use (&$lastRun): void {
            $interval = (int) config('audio.recovery.worker_loop_interval_seconds');
            if (time() - $lastRun < $interval) {
                return;
            }
            $lastRun = time();

            try {
                $this->app->make(SoundAudioRecovery::class)->run();
            } catch (\Throwable $e) {
                Log::error('audio.recovery.sweep_failed', ['message' => $e->getMessage()]);
            }
        });
    }

    private function configureRateLimiting(): void
    {
        // Strict limiter for unauthenticated login/token endpoints.
        //
        // 10 attempts per minute per IP makes automated brute-force impractical
        // (600/hr is far below what's needed to guess credentials at scale) while
        // still allowing a legitimate user to recover from repeated network errors
        // or accidental wrong-password retries without getting locked out.
        // Keyed by IP because no user session exists yet at these endpoints.
        RateLimiter::for('auth', function (Request $request): Limit {
            return Limit::perMinute(10)->by($request->ip());
        });

        // General limiter for all authenticated API routes.
        //
        // 60 requests per minute is the Laravel framework default and is generous
        // enough for normal mobile app usage (typical session: a handful of reads
        // plus one write per interaction). Keyed by authenticated user ID so that
        // multiple users sharing an IP (e.g. same household NAT, office network)
        // each get their own independent quota; falls back to IP for any
        // unauthenticated request that somehow reaches this limiter.
        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
