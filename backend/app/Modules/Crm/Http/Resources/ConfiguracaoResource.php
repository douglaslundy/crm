<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Resources;

use App\Modules\Crm\Domain\Models\ConfiguracaoDoCrm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ConfiguracaoDoCrm */
final class ConfiguracaoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['vendedor_ve_so_os_seus' => $this->vendedor_ve_so_os_seus];
    }
}
