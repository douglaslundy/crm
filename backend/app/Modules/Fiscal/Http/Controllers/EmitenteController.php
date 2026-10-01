<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Controllers;

use App\Modules\Fiscal\Application\Actions\AtualizarDadosDaEmpresa;
use App\Modules\Fiscal\Application\Actions\AtualizarDadosFiscais;
use App\Modules\Fiscal\Application\PoliticaDoEmitente;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Http\Requests\AtualizarDadosDaEmpresaRequest;
use App\Modules\Fiscal\Http\Requests\AtualizarDadosFiscaisRequest;
use App\Modules\Fiscal\Http\Resources\EmitenteResource;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Http\Request;

final class EmitenteController
{
    public function __construct(private readonly PoliticaDoEmitente $politica) {}

    public function show(): EmitenteResource
    {
        return new EmitenteResource($this->emitente()->load('series'));
    }

    public function atualizarEmpresa(AtualizarDadosDaEmpresaRequest $request, AtualizarDadosDaEmpresa $acao): EmitenteResource
    {
        $this->politica->garantirPodeEscrever($this->autor($request));

        return new EmitenteResource($acao->executar($this->emitente(), $request->dados())->load('series'));
    }

    public function atualizarFiscal(AtualizarDadosFiscaisRequest $request, AtualizarDadosFiscais $acao): EmitenteResource
    {
        $this->politica->garantirPodeEscrever($this->autor($request));

        return new EmitenteResource($acao->executar($this->emitente(), $request->dados())->load('series'));
    }

    private function emitente(): Emitente
    {
        $emitente = Emitente::query()->firstOrCreate([], []);
        // O emitente é materializado sob demanda; para o cliente da API isso nunca é um "201 Created".
        $emitente->wasRecentlyCreated = false;

        return $emitente;
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}
