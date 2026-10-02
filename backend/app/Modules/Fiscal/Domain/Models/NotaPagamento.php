<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $nota_id
 * @property string $tpag
 * @property ?string $xpag
 * @property int $valor_centavos
 */
class NotaPagamento extends Model
{
    use BelongsToTenant;

    protected $table = 'nota_pagamentos';

    protected $fillable = ['nota_id', 'tpag', 'xpag', 'valor_centavos'];
}
