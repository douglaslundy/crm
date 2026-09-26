<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Requests;

use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class MudarSituacaoRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'situacao' => ['required', Rule::enum(SituacaoAssinatura::class)],
            'motivo' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    public function situacao(): SituacaoAssinatura
    {
        return SituacaoAssinatura::from($this->string('situacao')->toString());
    }
}
