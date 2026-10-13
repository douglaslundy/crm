<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Requests;

use App\Modules\Crm\Domain\Enums\TipoDeAtividade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AtividadeRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $criando = $this->isMethod('POST');
        $obrigatorio = $criando ? 'required' : 'sometimes';

        return [
            'tipo' => [$obrigatorio, Rule::enum(TipoDeAtividade::class)],
            'titulo' => [$obrigatorio, 'string', 'min:1', 'max:150'],
            'descricao' => ['nullable', 'string', 'max:2000'],
            'agendada_para' => [$obrigatorio, 'date'],
            'responsavel_id' => ['nullable', 'uuid'],
            'cliente_id' => $criando ? ['required_without:negocio_id', 'nullable', 'uuid'] : ['prohibited'],
            'negocio_id' => $criando ? ['nullable', 'uuid'] : ['prohibited'],
        ];
    }
}
