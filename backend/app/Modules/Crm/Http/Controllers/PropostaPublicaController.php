<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Controllers;

use App\Modules\Crm\Application\Actions\AceitarProposta;
use App\Modules\Crm\Application\Actions\RecusarProposta;
use App\Modules\Crm\Application\ApresentadorDePropostas;
use App\Modules\Crm\Application\GeradorDePdfDaProposta;
use App\Modules\Crm\Application\ResolvedorDePropostaPublica;
use App\Modules\Crm\Http\Requests\AceiteRequest;
use App\Modules\Crm\Http\Requests\RecusaRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/** Sem sessão: tudo pelo token. O resolvedor fixa o tenant; qualquer token inválido é 404 idêntico. */
final class PropostaPublicaController
{
    public function __construct(private readonly ApresentadorDePropostas $apresentador) {}

    public function show(string $token, ResolvedorDePropostaPublica $resolvedor): JsonResponse
    {
        return response()->json(['data' => $this->apresentador->paraPublico($resolvedor->resolver($token))]);
    }

    public function pdf(string $token, ResolvedorDePropostaPublica $resolvedor, GeradorDePdfDaProposta $gerador): Response
    {
        $proposta = $resolvedor->resolver($token);

        return response($gerador->gerar($proposta), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="proposta-'.$proposta->numero.'.pdf"',
        ]);
    }

    public function aceitar(AceiteRequest $request, string $token, AceitarProposta $aceitar): JsonResponse
    {
        $proposta = $aceitar->executar($token, $request->string('nome')->toString(), $request->ip());

        return response()->json(['data' => $this->apresentador->paraPublico($proposta)]);
    }

    public function recusar(RecusaRequest $request, string $token, RecusarProposta $recusar): JsonResponse
    {
        $motivo = $request->filled('motivo') ? $request->string('motivo')->toString() : null;
        $proposta = $recusar->executar($token, $motivo, $request->ip());

        return response()->json(['data' => $this->apresentador->paraPublico($proposta)]);
    }
}
