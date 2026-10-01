<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UploadCertificadoRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'arquivo' => ['required', 'file', 'max:10240'],
            'senha' => ['required', 'string'],
        ];
    }
}
