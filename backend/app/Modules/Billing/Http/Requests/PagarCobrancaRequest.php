<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Domain\Enums\MetodoDePagamento;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PagarCobrancaRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'metodo' => ['required', Rule::enum(MetodoDePagamento::class)->only([MetodoDePagamento::Cartao, MetodoDePagamento::Pix])],
            // Obrigatório só no cartão; quem avisa é a Action (código estável BILLING_CARTAO_SEM_TOKEN).
            'token_do_cartao' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function metodo(): MetodoDePagamento
    {
        return MetodoDePagamento::from((string) $this->validated('metodo'));
    }
}
