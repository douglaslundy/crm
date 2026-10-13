<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Application\PreparadorDoCrm;
use App\Modules\Crm\Domain\Contracts\ConsultaDeClientes;
use App\Modules\Crm\Domain\Contracts\DiretorioDeUsuarios;
use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Events\NegocioCriado;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;
use Illuminate\Support\Facades\DB;

final class CriarNegocio
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly PreparadorDoCrm $preparador,
        private readonly ConsultaDeClientes $clientes,
        private readonly DiretorioDeUsuarios $usuarios,
        private readonly DefinirItensDoNegocio $itens,
    ) {}

    /**
     * @param  array{
     *     titulo: string, funil_id: string, etapa_id: string, cliente_id?: ?string,
     *     novo_lead?: ?array{nome: string, telefone?: ?string, email?: ?string},
     *     responsavel_id?: ?string, previsao_fechamento?: ?string, valor_centavos?: ?int,
     *     itens?: list<array{tipo: string, item_id: string, quantidade: int}>
     * }  $dados
     */
    public function executar(Usuario $autor, array $dados): Negocio
    {
        $this->politica->garantirPodeOperarNegocios($autor);

        return DB::transaction(function () use ($autor, $dados): Negocio {
            $this->preparador->garantir($autor);

            $funil = Funil::query()->where('ativo', true)->find($dados['funil_id'])
                ?? throw new RegraDoCrmException('Funil não encontrado ou inativo.', 'FUNIL_INVALIDO');
            // Trava a etapa: criações simultâneas na mesma etapa não repetem posição.
            $etapa = Etapa::query()->where('funil_id', $funil->id)->where('ativa', true)->lockForUpdate()->find($dados['etapa_id'])
                ?? throw new RegraDoCrmException('Etapa não encontrada neste funil.', 'ETAPA_INVALIDA');

            $responsavelId = $this->responsavel($autor, $dados['responsavel_id'] ?? null);
            $clienteId = $this->cliente($autor, $dados);

            $ultima = Negocio::query()->where('etapa_id', $etapa->id)->where('status', StatusDoNegocio::Aberto)->max('posicao_na_etapa');

            $negocio = Negocio::create([
                'titulo' => trim($dados['titulo']),
                'cliente_id' => $clienteId,
                'funil_id' => $funil->id,
                'etapa_id' => $etapa->id,
                'responsavel_id' => $responsavelId,
                'status' => StatusDoNegocio::Aberto,
                'valor_centavos' => $dados['valor_centavos'] ?? 0,
                'previsao_fechamento' => $dados['previsao_fechamento'] ?? null,
                'posicao_na_etapa' => $ultima === null ? 0 : (int) $ultima + 1,
            ]);

            if (($dados['itens'] ?? []) !== []) {
                $this->itens->aplicar($negocio, $dados['itens']);
            }

            DB::afterCommit(fn () => event(new NegocioCriado($autor->tenant_id ?? '', $negocio->id, $clienteId, $autor->id)));

            return $negocio->refresh()->load('itens');
        });
    }

    private function responsavel(Usuario $autor, ?string $pedido): string
    {
        if ($pedido === null || $pedido === $autor->id) {
            return $autor->id;
        }
        if (! $this->politica->podeReatribuir($autor)) {
            throw new AcessoNegadoException('Só proprietário e administrador atribuem outro responsável.');
        }
        if (! $this->usuarios->ehDaEmpresa($pedido)) {
            throw new RegraDoCrmException('Responsável não encontrado.', 'RESPONSAVEL_INVALIDO');
        }

        return $pedido;
    }

    /** @param array<string, mixed> $dados */
    private function cliente(Usuario $autor, array $dados): string
    {
        $id = $dados['cliente_id'] ?? null;
        if ($id !== null) {
            if ($this->clientes->resumo($id) === null) {
                throw new RegraDoCrmException('Cliente não encontrado.', 'CLIENTE_NAO_ENCONTRADO');
            }

            return $id;
        }

        $lead = $dados['novo_lead'] ?? null;
        if (! is_array($lead) || ($lead['nome'] ?? '') === '') {
            throw new RegraDoCrmException('Informe um cliente ou os dados do novo lead.', 'CLIENTE_OBRIGATORIO');
        }

        return $this->clientes->criarLead($autor, trim((string) $lead['nome']), $lead['telefone'] ?? null, $lead['email'] ?? null);
    }
}
