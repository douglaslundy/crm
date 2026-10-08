<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/** Converte qualquer exceção do /api/v1 em problem+json. O /api/app não passa por aqui. */
final class TratadorDeErrosDaApi
{
    public function tratar(Throwable $e, Request $request): JsonResponse
    {
        $instance = '/'.ltrim($request->path(), '/');

        return match (true) {
            $e instanceof ErroDeNegocio => ErroApiV1::resposta($e->status(), $e->codigo(), $e->getMessage(), $instance, null, $e->extras(), $e->headers()),
            $e instanceof ValidationException => ErroApiV1::resposta(422, 'VALIDACAO', 'Os dados enviados são inválidos.', $instance, $this->campos($e)),
            $e instanceof AuthenticationException => ErroApiV1::resposta(401, 'API_CHAVE_INVALIDA', 'Chave de API inválida ou revogada.', $instance, null, [], ['WWW-Authenticate' => 'Bearer']),
            $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => ErroApiV1::resposta(403, 'ACESSO_NEGADO', 'Você não tem permissão para esta ação.', $instance),
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => ErroApiV1::resposta(404, 'RECURSO_NAO_ENCONTRADO', 'O recurso solicitado não existe.', $instance),
            $e instanceof MethodNotAllowedHttpException => ErroApiV1::resposta(405, 'METODO_NAO_PERMITIDO', 'Este método não é permitido neste endereço.', $instance, null, [], $this->cabecalhos($e)),
            $e instanceof HttpExceptionInterface && $e->getStatusCode() === 429 => ErroApiV1::resposta(429, 'API_LIMITE_EXCEDIDO', 'Muitas requisições. Aguarde um pouco e tente novamente.', $instance, null, [], $this->cabecalhos($e)),
            $e instanceof HttpExceptionInterface && $e->getStatusCode() < 500 => ErroApiV1::resposta($e->getStatusCode(), 'REQUISICAO_INVALIDA', 'A requisição não pôde ser processada.', $instance, null, [], $this->cabecalhos($e)),
            default => ErroApiV1::resposta(500, 'ERRO_INTERNO', 'Ocorreu um erro interno. Tente novamente mais tarde.', $instance),
        };
    }

    /** @return list<array{campo: string, mensagem: string}> */
    private function campos(ValidationException $e): array
    {
        $lista = [];
        foreach ($e->errors() as $campo => $mensagens) {
            foreach ($mensagens as $mensagem) {
                $lista[] = ['campo' => (string) $campo, 'mensagem' => $mensagem];
            }
        }

        return $lista;
    }

    /** @return array<string, string> */
    private function cabecalhos(HttpExceptionInterface $e): array
    {
        return array_map(fn (mixed $valor): string => is_array($valor) ? implode(', ', $valor) : (string) $valor, $e->getHeaders());
    }
}
