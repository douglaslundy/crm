<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Webhooks;

use App\Modules\Fiscal\Domain\Enums\AmbienteFiscal;
use App\Modules\Fiscal\Domain\Enums\EventoDeWebhook;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Regras dos campos de um endpoint, iguais para a criação e a alteração (esta só valida o que veio). */
final class ValidadorDeWebhook
{
    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed> só as chaves enviadas, já validadas
     */
    public function validar(array $dados, bool $parcial): array
    {
        $obrigatorio = $parcial ? 'sometimes' : 'required';

        return Validator::make($dados, [
            'url' => [$obrigatorio, 'string'],
            'descricao' => ['sometimes', 'nullable', 'string', 'max:80'],
            'eventos' => ['sometimes', 'nullable', 'array'],
            'eventos.*' => ['string', 'distinct', Rule::in(EventoDeWebhook::assinaveis())],
            'ambiente' => [$obrigatorio, Rule::enum(AmbienteFiscal::class)],
        ], [
            'eventos.*.in' => 'Evento desconhecido. Use: '.implode(', ', EventoDeWebhook::assinaveis()).'.',
        ])->validate();
    }
}
