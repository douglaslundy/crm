<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Domain\Enums\CicloDeCobranca;
use App\Modules\Billing\Domain\Enums\MetodoDePagamento;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CheckoutRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'plano_id' => ['required', 'uuid'],
            'ciclo' => ['required', Rule::enum(CicloDeCobranca::class)],
            'metodo' => ['required', Rule::enum(MetodoDePagamento::class)],
            // Obrigatório só no cartão; quem avisa é a Action (código estável BILLING_CARTAO_SEM_TOKEN).
            'token_do_cartao' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function ciclo(): CicloDeCobranca
    {
        return CicloDeCobranca::from((string) $this->validated('ciclo'));
    }

    public function metodo(): MetodoDePagamento
    {
        return MetodoDePagamento::from((string) $this->validated('metodo'));
    }
}
