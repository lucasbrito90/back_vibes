<?php

declare(strict_types=1);

namespace App\Services\Audio;

/**
 * Publication state of a sound's *current* audio (what `file_url` points at).
 *
 * Legacy: pre-versioning URL still served, object not yet verified by the backfill.
 * Pending: nothing published yet (first upload still processing). Ready: verified, versioned asset.
 */
enum SoundAudioStatus: string
{
    case Legacy = 'legacy';
    case Pending = 'pending';
    case Ready = 'ready';

    /** @return list<string> */
    public static function playableValues(): array
    {
        return [self::Legacy->value, self::Ready->value];
    }
}
