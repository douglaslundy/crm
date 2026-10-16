<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Requests;

use App\Modules\Platform\Application\DesafioAntiRobo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ContatoSiteRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:150'],
            'mensagem' => ['required', 'string', 'min:5', 'max:2000'],
            'desafio_token' => ['required', 'string'],
            'desafio_resposta' => ['required', 'string', 'max:10'],
            // Armadilha para robô: o campo fica escondido na tela e humano nunca o preenche.
            'site' => ['nullable', 'max:0'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $v): void {
            if ($v->errors()->isEmpty() && ! app(DesafioAntiRobo::class)->conferir((string) $this->input('desafio_token'), (string) $this->input('desafio_resposta'))) {
                $v->errors()->add('desafio_resposta', 'Resposta errada. Confira a conta e tente de novo.');
            }
        }];
    }
}
