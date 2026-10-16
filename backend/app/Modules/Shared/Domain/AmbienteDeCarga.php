<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain;

use RuntimeException;

/** O staging roda com APP_ENV=production, então o guarda é uma variável própria. Fail-closed: o padrão é `producao`. */
final class AmbienteDeCarga
{
    public static function permitido(): bool
    {
        return config('app.ambiente') === 'staging';
    }

    public static function exigir(string $recurso): void
    {
        if (! self::permitido()) {
            throw new RuntimeException("{$recurso} só roda com APP_AMBIENTE=staging.");
        }
    }
}
