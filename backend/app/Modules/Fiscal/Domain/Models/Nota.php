<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Models;

use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Enums\TipoDeContingencia;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Database\Factories\NotaFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $emitente_id
 * @property ModeloDocumento $modelo
 * @property ?string $cliente_id
 * @property StatusNota $status
 * @property ?string $ambiente
 * @property string $natureza_operacao
 * @property ?bool $consumidor_final
 * @property int $subtotal_centavos
 * @property int $desconto_centavos
 * @property int $total_centavos
 * @property ?string $informacoes_complementares
 * @property ?array<string, mixed> $destinatario
 * @property ?string $serie
 * @property ?int $numero
 * @property int $tentativas_numero
 * @property ?string $chave
 * @property ?string $numero_nfse
 * @property ?string $protocolo
 * @property ?string $cstat
 * @property ?string $motivo
 * @property ?string $mensagem_erro
 * @property ?string $xml_enviado
 * @property ?string $xml_autorizado
 * @property ?CarbonInterface $iniciada_em
 * @property ?CarbonInterface $emitido_em
 * @property ?CarbonInterface $cancelamento_solicitado_em
 * @property ?string $cancelamento_justificativa
 * @property ?string $cancelamento_protocolo
 * @property ?CarbonInterface $cancelado_em
 * @property ?string $cancelamento_cstat
 * @property ?string $cancelamento_motivo
 * @property ?string $xml_cancelamento
 * @property ?CarbonInterface $contingencia_desde
 * @property ?CarbonInterface $contingencia_limite_em
 * @property ?CarbonInterface $ultima_retransmissao_em
 * @property ?string $contingencia_motivo
 * @property ?TipoDeContingencia $contingencia_tipo
 * @property ?string $epec_protocolo
 * @property ?CarbonInterface $epec_registrado_em
 * @property ?string $xml_epec
 * @property CarbonInterface $created_at
 */
class Nota extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<NotaFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'notas';

    /** Campos de retorno da SEFAZ só mudam pelas Actions (forceFill). */
    protected $fillable = [
        'emitente_id', 'cliente_id', 'consumidor_final', 'desconto_centavos', 'informacoes_complementares',
        'subtotal_centavos', 'total_centavos',
    ];

    protected $hidden = ['xml_enviado', 'xml_autorizado', 'xml_cancelamento', 'xml_epec'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'modelo' => 'NFE', 'status' => 'RASCUNHO', 'natureza_operacao' => 'Venda de mercadoria',
        'subtotal_centavos' => 0, 'desconto_centavos' => 0, 'total_centavos' => 0, 'tentativas_numero' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => StatusNota::class,
            'modelo' => ModeloDocumento::class,
            'contingencia_desde' => 'datetime',
            'contingencia_limite_em' => 'datetime',
            'ultima_retransmissao_em' => 'datetime',
            'contingencia_tipo' => TipoDeContingencia::class,
            'epec_registrado_em' => 'datetime',
            'consumidor_final' => 'boolean',
            'destinatario' => 'array',
            'iniciada_em' => 'datetime',
            'emitido_em' => 'datetime',
            'cancelamento_solicitado_em' => 'datetime',
            'cancelado_em' => 'datetime',
        ];
    }

    /** @return HasMany<NotaItem, $this> */
    public function itens(): HasMany
    {
        return $this->hasMany(NotaItem::class)->orderBy('ordem');
    }

    /** @return HasMany<NotaPagamento, $this> */
    public function pagamentos(): HasMany
    {
        return $this->hasMany(NotaPagamento::class);
    }

    /** @return HasMany<NotaEvento, $this> */
    public function eventos(): HasMany
    {
        return $this->hasMany(NotaEvento::class)->orderBy('id');
    }

    /** @return BelongsTo<Cliente, $this> */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    protected static function newFactory(): NotaFactory
    {
        return NotaFactory::new();
    }
}
