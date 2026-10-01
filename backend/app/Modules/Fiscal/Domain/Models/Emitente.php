<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Models;

use App\Modules\Fiscal\Domain\Enums\AmbienteFiscal;
use App\Modules\Fiscal\Domain\Enums\CertificadoStatus;
use App\Modules\Tenancy\Domain\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Database\Factories\EmitenteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property ?string $uf
 * @property ?string $regime_tributario
 * @property AmbienteFiscal $ambiente_fiscal
 * @property ?CarbonInterface $certificado_validade
 * @property ?string $certificado_titular
 * @property CertificadoStatus $certificado_status
 * @property ?string $csc_id_homologacao
 * @property ?string $csc_id_producao
 */
class Emitente extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<EmitenteFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'logradouro', 'numero', 'bairro', 'cidade', 'uf', 'cep', 'codigo_ibge',
        'regime_tributario', 'inscricao_estadual', 'inscricao_municipal', 'cnae', 'ambiente_fiscal',
        'certificado_pfx_encrypted', 'certificado_senha_encrypted', 'certificado_validade',
        'certificado_titular', 'certificado_status',
        'csc_id_homologacao', 'csc_token_homologacao_encrypted', 'csc_id_producao', 'csc_token_producao_encrypted',
    ];

    protected $hidden = ['certificado_pfx_encrypted', 'certificado_senha_encrypted', 'csc_token_homologacao_encrypted', 'csc_token_producao_encrypted'];

    /**
     * O default do banco (`->default(...)` na migration) não é lido de volta para o
     * Model em memória num `create([])`; precisa estar aqui também.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'ambiente_fiscal' => 'HOMOLOGACAO',
        'certificado_status' => 'PENDENTE',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ambiente_fiscal' => AmbienteFiscal::class,
            'certificado_status' => CertificadoStatus::class,
            'certificado_validade' => 'date',
        ];
    }

    /** @return HasMany<EmitenteSerie, $this> */
    public function series(): HasMany
    {
        return $this->hasMany(EmitenteSerie::class);
    }

    protected static function newFactory(): EmitenteFactory
    {
        return EmitenteFactory::new();
    }
}
