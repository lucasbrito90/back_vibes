<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Schedule;
use App\Models\ScheduleExecution;
use App\Services\Scheduling\RecurrenceService;
use App\Services\Scheduling\RecurrenceType;
use App\Services\Scheduling\ScheduleInput;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Dispatcher command for scheduler MVP (Phase 5).
 *
 * Processes due schedules idempotently, appending schedule_executions rows
 * and advancing next_run_at. Runs in the DO App Platform Scheduled Job every minute.
 *
 * ADR-009: UTC storage + timezone-aware recurrence expansion.
 * ADR-010: occurrence_key = "{schedule_id}:{scheduled_for_unix}" as idempotency guard.
 * spec.md § next_run_at behaviour, § Idempotency strategy.
 */
final class DispatchDueSchedulesCommand extends Command
{
    protected $signature = 'schedules:dispatch-due
                            {--batch=100 : Maximum number of due schedules to process per run}
                            {--dry-run : Report how many schedules would be processed without persisting any changes}';

    protected $description = 'Process due schedules and record schedule_executions idempotently.';

    public function handle(RecurrenceService $recurrenceService): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $batchSize = max(1, (int) $this->option('batch'));
        $nowUtc = CarbonImmutable::now('UTC');

        $due = Schedule::query()
            ->where('is_enabled', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $nowUtc)
            ->orderBy('next_run_at')
            ->limit($batchSize)
            ->get();

        $dueCount = $due->count();
        $dispatched = 0;
        $skippedDuplicate = 0;
        $failed = 0;

        if ($isDryRun) {
            $this->info("Dry run — {$dueCount} due schedule(s) would be processed (no changes written).");

            return self::SUCCESS;
        }

        foreach ($due as $schedule) {
            try {
                $result = $this->processSchedule($schedule, $recurrenceService, $nowUtc);

                if ($result === 'dispatched') {
                    $dispatched++;
                } elseif ($result === 'skipped_duplicate') {
                    $skippedDuplicate++;
                }
            } catch (Throwable $e) {
                $failed++;
                $this->warn("Schedule [{$schedule->id}] failed: {$e->getMessage()}");
            }
        }

        $this->outputSummary($dueCount, $dispatched, $skippedDuplicate, $failed, $isDryRun);

        return self::SUCCESS;
    }

    /**
     * Process one due schedule inside a transaction.
     *
     * @return 'dispatched'|'skipped_duplicate'
     *
     * @throws Throwable
     */
    private function processSchedule(
        Schedule $schedule,
        RecurrenceService $recurrenceService,
        CarbonImmutable $nowUtc,
    ): string {
        /** @var 'dispatched'|'skipped_duplicate' $result */
        $result = DB::transaction(function () use ($schedule, $recurrenceService, $nowUtc): string {
            $scheduledFor = CarbonImmutable::parse($schedule->next_run_at)->utc();
            $occurrenceKey = $recurrenceService->computeOccurrenceKey($schedule->id, $scheduledFor);

            // Optimistic pre-check: fast path for duplicate cron ticks (common case).
            // The unique index on (schedule_id, occurrence_key) is the final DB-level guard.
            $alreadyExists = ScheduleExecution::query()
                ->where('schedule_id', $schedule->id)
                ->where('occurrence_key', $occurrenceKey)
                ->exists();

            if ($alreadyExists) {
                return 'skipped_duplicate';
            }

            try {
                ScheduleExecution::query()->create([
                    'schedule_id' => $schedule->id,
                    'occurrence_key' => $occurrenceKey,
                    'scheduled_for' => $scheduledFor,
                    'executed_at' => $nowUtc,
                    'status' => 'dispatched',
                    'log' => json_encode([
                        'command' => 'schedules:dispatch-due',
                        'batch_time_utc' => $nowUtc->toIso8601String(),
                    ]),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Race condition (two dispatcher instances running simultaneously):
                // another process inserted the same occurrence between our pre-check and INSERT.
                return 'skipped_duplicate';
            }

            $input = new ScheduleInput(
                timezone: $schedule->timezone,
                startTime: CarbonImmutable::parse($schedule->start_time)->utc(),
                recurrenceType: RecurrenceType::from($schedule->recurrence_type),
                recurrenceConfig: $schedule->recurrence_config,
                isEnabled: true,
            );

            $nextRunAt = $recurrenceService->computeNextRunAt($input, $scheduledFor);

            $schedule->last_run_at = $scheduledFor;
            $schedule->next_run_at = $nextRunAt;

            if ($nextRunAt === null) {
                $schedule->is_enabled = false;
            }

            $schedule->save();

            return 'dispatched';
        });

        return $result;
    }

    private function outputSummary(
        int $due,
        int $dispatched,
        int $skippedDuplicate,
        int $failed,
        bool $isDryRun,
    ): void {
        $this->line('');
        $this->line('schedules:dispatch-due summary');
        $this->line('------------------------------');
        $this->line("  due              : {$due}");
        $this->line("  dispatched       : {$dispatched}");
        $this->line("  skipped_duplicate: {$skippedDuplicate}");
        $this->line("  failed           : {$failed}");
        $this->line('  dry_run          : '.($isDryRun ? 'true' : 'false'));
        $this->line('');
    }
}
