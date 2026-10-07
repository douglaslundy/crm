<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Emissao;

use App\Modules\Fiscal\Domain\Enums\TipoDeContingencia;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Dados da entrada em contingência (`dhCont` e `xJust` do XML): justificativa de 15 a 255 caracteres. */
final readonly class ContingenciaDeEmissao
{
    public function __construct(
        public string $justificativa,
        public CarbonImmutable $em,
        public TipoDeContingencia $tipo = TipoDeContingencia::OfflineNfce,
    ) {
        $tamanho = mb_strlen(trim($justificativa));
        if ($tamanho < 15 || $tamanho > 255) {
            throw new InvalidArgumentException('A justificativa da contingência deve ter de 15 a 255 caracteres.');
        }
    }
}
