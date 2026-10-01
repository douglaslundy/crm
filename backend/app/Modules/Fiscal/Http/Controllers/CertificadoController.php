<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Controllers;

use App\Modules\Fiscal\Application\Actions\ProcessarCertificado;
use App\Modules\Fiscal\Application\PoliticaDoEmitente;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Http\Requests\UploadCertificadoRequest;
use App\Modules\Fiscal\Http\Resources\EmitenteResource;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Http\Request;

final class CertificadoController
{
    public function __construct(private readonly PoliticaDoEmitente $politica) {}

    public function store(UploadCertificadoRequest $request, ProcessarCertificado $acao): EmitenteResource
    {
        $this->politica->garantirPodeEscrever($this->autor($request));
        $emitente = $this->emitente();
        $conteudo = (string) $request->file('arquivo')?->get();

        return new EmitenteResource(
            $acao->executar($emitente, $conteudo, (string) $request->input('senha'))->load('series'),
        );
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
