<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Crm\Domain\Enums\StatusDaProposta;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Proposta;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class ValidadorDeProposta
{
    /** Desconto 0 é válido; desconto igual ou maior que o subtotal deixaria a proposta sem valor. */
    public static function garantirDescontoValido(Proposta $proposta): void
    {
        $proposta->unsetRelation('itens')->load('itens');
        $subtotal = $proposta->subtotalCentavos();
        if ($proposta->desconto_centavos >= $subtotal) {
            throw new RegraDoCrmException(
                'O desconto deve ser menor que o total dos itens (R$ '.number_format($subtotal / 100, 2, ',', '.').').',
                'PROPOSTA_DESCONTO_INVALIDO',
            );
        }
    }

    public static function garantirEditavel(Proposta $proposta): void
    {
        if ($proposta->status !== StatusDaProposta::Rascunho) {
            throw new RegraDoCrmException('Só proposta em rascunho pode ser editada ou enviada.', 'PROPOSTA_NAO_EDITAVEL');
        }
    }

    /** Aceite e recusa públicos: só `ENVIADA` e dentro da validade. */
    public static function garantirRespondivel(Proposta $proposta): void
    {
        if (in_array($proposta->status, [StatusDaProposta::Aceita, StatusDaProposta::Recusada], true)) {
            throw new RegraDoCrmException('Esta proposta já foi respondida.', 'PROPOSTA_JA_RESPONDIDA');
        }
        if ($proposta->status !== StatusDaProposta::Enviada) {
            throw (new ModelNotFoundException)->setModel(Proposta::class);
        }
        if ($proposta->expirada()) {
            throw new RegraDoCrmException('Esta proposta expirou. Peça uma nova ao vendedor.', 'PROPOSTA_EXPIRADA');
        }
    }
}
