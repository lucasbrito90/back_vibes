<?php

declare(strict_types=1);

namespace App\Console\Commands\SmartHome;

use App\Models\SceneActionExecution;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Prunes scene_action_executions rows older than the ADR-034 retention window.
 */
final class PruneSceneActionExecutionsCommand extends Command
{
    private const RETENTION_DAYS = 90;

    private const BATCH_SIZE = 1000;

    protected $signature = 'smart-home:prune-executions';

    protected $description = 'Delete scene action execution records older than 90 days';

    public function handle(): int
    {
        $cutoff = Carbon::now()->subDays(self::RETENTION_DAYS);
        $totalDeleted = 0;

        try {
            do {
                $deleted = SceneActionExecution::query()
                    ->where('created_at', '<', $cutoff)
                    ->orderBy('id')
                    ->limit(self::BATCH_SIZE)
                    ->delete();

                $totalDeleted += $deleted;
            } while ($deleted === self::BATCH_SIZE);
        } catch (Throwable $e) {
            Log::warning('smart-home:prune-executions failed.', [
                'exception_class' => $e::class,
                'deleted_before_failure' => $totalDeleted,
            ]);

            $this->warn('Prune failed — see logs for details.');

            return self::FAILURE;
        }

        $this->info("Pruned {$totalDeleted} scene action execution record(s) older than ".self::RETENTION_DAYS.' days.');

        return self::SUCCESS;
    }
}
