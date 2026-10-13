<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\LinkDeContato;
use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Application\RegistradorDaTimeline;
use App\Modules\Crm\Application\ValidadorDeAtividade;
use App\Modules\Crm\Domain\Contracts\ConsultaDeClientes;
use App\Modules\Crm\Domain\Enums\TipoDeAtividade;
use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

final class RegistrarContato
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly ValidadorDeAtividade $validador,
        private readonly ConsultaDeClientes $clientes,
        private readonly LinkDeContato $links,
        private readonly RegistradorDaTimeline $timeline,
    ) {}

    /** @return array{atividade: Atividade, url: string} */
    public function executar(Usuario $autor, string $canal, ?string $clienteId, ?string $negocioId): array
    {
        $this->politica->garantirPodeOperarNegocios($autor);

        $tipo = match ($canal) {
            'WHATSAPP' => TipoDeAtividade::Whatsapp,
            'EMAIL' => TipoDeAtividade::Email,
            default => throw new RegraDoCrmException('Canal de contato inválido.', 'CANAL_INVALIDO'),
        };

        [$clienteId, $negocioId] = $this->validador->alvo($autor, $clienteId, $negocioId);
        $cliente = $this->clientes->resumo($clienteId)
            ?? throw new RegraDoCrmException('Cliente não encontrado.', 'CLIENTE_INVALIDO');

        // O link é montado antes de gravar: dado inválido não deixa atividade nem registro para trás.
        $url = $tipo === TipoDeAtividade::Whatsapp
            ? $this->links->whatsapp($cliente->telefone)
            : $this->links->email($cliente->email);

        $titulo = ($tipo === TipoDeAtividade::Whatsapp ? 'WhatsApp para ' : 'E-mail para ').$cliente->nome;

        $atividade = DB::transaction(function () use ($autor, $tipo, $clienteId, $negocioId, $titulo): Atividade {
            $agora = now();
            $atividade = Atividade::create([
                'cliente_id' => $clienteId, 'negocio_id' => $negocioId, 'tipo' => $tipo, 'titulo' => $titulo,
                'agendada_para' => $agora, 'concluida_em' => $agora,
                'responsavel_id' => $autor->id, 'criado_por' => $autor->id,
            ]);

            $this->timeline->registrar(
                TipoDeRegistro::AtividadeConcluida, $clienteId, $negocioId, $autor->id, $titulo,
                ['tipo' => $tipo->value, 'titulo' => $titulo], $atividade->id, $agora,
            );

            return $atividade;
        });

        return ['atividade' => $atividade, 'url' => $url];
    }
}
