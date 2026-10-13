<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Requests;

use App\Modules\Crm\Domain\Enums\TipoDeItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ItensDoNegocioRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'itens' => ['present', 'array'],
            'itens.*.tipo' => ['required', Rule::enum(TipoDeItem::class)],
            'itens.*.item_id' => ['required', 'uuid'],
            'itens.*.quantidade' => ['required', 'integer', 'min:1', 'max:100000'],
        ];
    }
}
