<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Mensagem enviada pelo formulário da página de vendas. Não pertence a empresa nenhuma
 * (visitante anônimo), por isso não tem escopo de tenant.
 *
 * @property string $id
 * @property string $nome
 * @property string $email
 * @property string $mensagem
 * @property string|null $ip
 */
class ContatoSite extends Model
{
    use HasUuids;

    protected $table = 'contatos_site';

    protected $fillable = ['nome', 'email', 'mensagem', 'ip'];
}
