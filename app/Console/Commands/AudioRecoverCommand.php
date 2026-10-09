<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Audio\SoundAudioRecovery;
use Illuminate\Console\Command;

final class AudioRecoverCommand extends Command
{
    protected $signature = 'audio:recover';

    protected $description = 'Re-dispatch stuck or lost audio revisions, fail exhausted ones, and retry pending storage deletions.';

    public function handle(SoundAudioRecovery $recovery): int
    {
        $result = $recovery->run();

        $this->line(sprintf(
            '[audio:recover] requeued=%d failed=%d deletions_done=%d deletions_pending=%d temp_dirs_removed=%d',
            $result['requeued'],
            $result['failed'],
            $result['deletions_done'],
            $result['deletions_pending'],
            $result['temp_dirs_removed'],
        ));

        return self::SUCCESS;
    }
}
