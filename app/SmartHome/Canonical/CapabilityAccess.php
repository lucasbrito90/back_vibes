<?php

declare(strict_types=1);

namespace App\SmartHome\Canonical;

enum CapabilityAccess: string
{
    case Read = 'read';
    case Write = 'write';
    case ReadWrite = 'read_write';

    public function permitsWrite(): bool
    {
        return $this === self::Write || $this === self::ReadWrite;
    }
}
