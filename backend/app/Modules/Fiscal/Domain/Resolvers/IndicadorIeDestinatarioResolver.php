<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Resolvers;

use App\Modules\Customers\Domain\Enums\TipoCliente;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;

/** `indIEDest`: 1 contribuinte, 2 isento, 9 não contribuinte. Não confundir com `indFinal`. */
final class IndicadorIeDestinatarioResolver
{
    public function resolver(TipoCliente $tipo, ?string $ie, bool $isento): int
    {
        if ($tipo === TipoCliente::Pf) {
            return 9;
        }

        $temIe = $ie !== null && trim($ie) !== '';
        if ($temIe && $isento) {
            throw new EmissaoBloqueadaException('FISCAL_DESTINATARIO_IE_CONTRADITORIA', 'O cliente está marcado como isento mas tem inscrição estadual. Corrija o cadastro.');
        }
        if ($isento) {
            return 2;
        }
        if ($temIe) {
            return 1;
        }

        throw new EmissaoBloqueadaException('FISCAL_DESTINATARIO_SEM_IE', 'Cliente pessoa jurídica sem inscrição estadual. Informe a IE ou marque como isento no cadastro do cliente.');
    }
}
