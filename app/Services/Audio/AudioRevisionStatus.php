<?php

declare(strict_types=1);

namespace App\Services\Audio;

/**
 * Lifecycle of one upload attempt (revision) for a sound's audio.
 *
 * queued → processing → ready | failed | superseded. A `ready` revision is the one that was published
 * (or was published and later replaced by a higher version); `superseded` revisions never became current.
 */
enum AudioRevisionStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
    case Superseded = 'superseded';

    /** @return list<self> */
    public static function inFlight(): array
    {
        return [self::Queued, self::Processing];
    }

    public function isTerminal(): bool
    {
        return ! in_array($this, self::inFlight(), true);
    }
}
