<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Enums;

enum AmbienteDaChave: string
{
    case Live = 'LIVE';
    case Test = 'TEST';

    public function prefixoDaChave(): string
    {
        return match ($this) {
            self::Live => 'live_',
            self::Test => 'test_',
        };
    }
}
