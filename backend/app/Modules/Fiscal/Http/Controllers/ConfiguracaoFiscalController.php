<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Controllers;

use App\Modules\Fiscal\Application\Actions\AtualizarCsc;
use App\Modules\Fiscal\Application\Actions\AtualizarSerie;
use App\Modules\Fiscal\Application\Actions\ConfirmarProducao;
use App\Modules\Fiscal\Application\PoliticaDoEmitente;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Http\Requests\AtualizarCscRequest;
use App\Modules\Fiscal\Http\Requests\AtualizarSerieRequest;
use App\Modules\Fiscal\Http\Requests\ConfirmarProducaoRequest;
use App\Modules\Fiscal\Http\Resources\EmitenteResource;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Http\Request;

final class ConfiguracaoFiscalController
{
    public function __construct(private readonly PoliticaDoEmitente $politica) {}

    public function atualizarCsc(AtualizarCscRequest $request, AtualizarCsc $acao): EmitenteResource
    {
        $this->politica->garantirPodeEscrever($this->autor($request));

        return new EmitenteResource($acao->executar($this->emitente(), $request->dados())->load('series'));
    }

    public function atualizarSerie(AtualizarSerieRequest $request, string $modelo, AtualizarSerie $acao): EmitenteResource
    {
        $this->politica->garantirPodeEscrever($this->autor($request));
        $acao->executar($this->emitente(), ModeloDocumento::from($modelo), (string) $request->input('serie'), (int) $request->input('proximo_numero'));

        return new EmitenteResource($this->emitente()->load('series'));
    }

    public function confirmarProducao(ConfirmarProducaoRequest $request, ConfirmarProducao $acao): EmitenteResource
    {
        $this->politica->garantirPodeEscrever($this->autor($request));

        return new EmitenteResource($acao->executar($this->emitente(), $this->autor($request))->load('series'));
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
