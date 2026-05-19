<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ResetIxoraContentCommand extends Command
{
    /**
     * Tables cleared in dependency order (children before parents).
     *
     * Does not touch users, admin_access_requests, devices, user_settings, jobs, cache, sessions, etc.
     *
     * @var list<string>
     */
    private const TABLES_IN_DELETE_ORDER = [
        'vibe_sounds',
        'preset_vibe_sounds',
        'schedule_executions',
        'schedules',
        'vibe_device_actions',
        'vibes',
        'preset_vibes',
        'cover_bundles',
        'sounds',
    ];

    protected $signature = 'ixora:reset-content {--confirm : Confirm destructive content reset}';

    protected $description = 'Delete Ixora catalog content (sounds, vibes, presets, covers, pivots, schedules) while preserving users and admin access requests.';

    public function handle(): int
    {
        if (! $this->option('confirm')) {
            $this->warn('This command deletes Ixora content data but preserves users.');
            $this->line('Run again with --confirm to proceed.');

            return Command::FAILURE;
        }

        if ($this->laravel->environment('production') && ! $this->laravel->runningUnitTests()) {
            if (! $this->confirm('You are running this in production. Are you absolutely sure?', false)) {
                $this->warn('Aborted.');

                return Command::FAILURE;
            }
        }

        $this->info('Ixora content reset started...');

        /** @var list<array{0: string, 1: int}> $summary */
        $summary = [];

        try {
            DB::transaction(function () use (&$summary): void {
                foreach (self::TABLES_IN_DELETE_ORDER as $table) {
                    if (! Schema::hasTable($table)) {
                        $this->comment("Skipped {$table} (table does not exist).");
                        Log::info('Ixora content reset skipped table (missing)', ['table' => $table]);

                        continue;
                    }

                    $count = (int) DB::table($table)->count();

                    if ($count === 0) {
                        $this->line("Deleted 0 rows from {$table}");
                        Log::info('Ixora content reset empty table', ['table' => $table, 'deleted' => 0]);
                        $summary[] = [$table, 0];

                        continue;
                    }

                    DB::table($table)->delete();

                    $this->info("Deleted {$count} rows from {$table}");
                    Log::info('Ixora content reset deleted rows', ['table' => $table, 'deleted' => $count]);
                    $summary[] = [$table, $count];
                }
            });
        } catch (Throwable $e) {
            $this->error('Ixora content reset failed: '.$e->getMessage());
            Log::error('Ixora content reset failed', ['exception' => $e]);

            return Command::FAILURE;
        }

        $this->newLine();
        $this->info('Ixora content reset completed.');
        $this->table(['Table', 'Rows deleted'], $summary);

        $total = array_sum(array_column($summary, 1));
        $this->line('Total rows deleted: '.$total);

        return Command::SUCCESS;
    }
}
