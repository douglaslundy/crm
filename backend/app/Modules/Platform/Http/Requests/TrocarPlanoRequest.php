<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class TrocarPlanoRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['plano_id' => ['required', 'uuid', 'exists:planos,id']];
    }
}
