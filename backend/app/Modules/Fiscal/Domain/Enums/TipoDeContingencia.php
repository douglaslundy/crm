<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Enums;

/** Como a nota foi emitida sem a SEFAZ: NFC-e offline (tpEmis 9) ou NF-e em EPEC (tpEmis 4). */
enum TipoDeContingencia: string
{
    case OfflineNfce = 'OFFLINE_NFCE';
    case Epec = 'EPEC';

    public function prazoEmHoras(): int
    {
        return (int) config($this === self::Epec ? 'fiscal.epec_prazo_horas' : 'fiscal.contingencia_prazo_horas');
    }

    public function alertaEmHoras(): int
    {
        return (int) config($this === self::Epec ? 'fiscal.epec_alerta_horas' : 'fiscal.contingencia_alerta_horas');
    }

    public function rotulo(): string
    {
        return $this === self::Epec ? 'EPEC' : 'contingência offline';
    }
}
