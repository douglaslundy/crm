<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Contracts;

/** Desenha o DANFSe v2.0 a partir do XML autorizado da NFS-e. `$cancelada` troca a situação impressa. */
interface GeradorDeDanfse
{
    /** @return string bytes do PDF (A4, 595x842 pt) */
    public function gerar(string $xml, bool $cancelada): string;
}
