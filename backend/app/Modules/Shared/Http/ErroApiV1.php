<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http;

use Illuminate\Http\JsonResponse;

/** Monta a resposta de erro do /api/v1 no formato RFC 9457 (application/problem+json). */
final class ErroApiV1
{
    /**
     * @param  list<array{campo: string, mensagem: string}>|null  $errors
     * @param  array<string, mixed>  $extras
     * @param  array<string, string>  $headers
     */
    public static function resposta(int $status, string $codigo, string $detail, string $instance, ?array $errors = null, array $extras = [], array $headers = []): JsonResponse
    {
        $corpo = [
            'type' => config('api.erros_base_url').'/'.strtolower($codigo),
            'title' => self::titulo($status),
            'status' => $status,
            'detail' => $detail,
            'codigo' => $codigo,
            'instance' => $instance,
        ];
        if ($errors !== null) {
            $corpo['errors'] = $errors;
        }

        return new JsonResponse($corpo + $extras, $status, $headers + ['Content-Type' => 'application/problem+json']);
    }

    private static function titulo(int $status): string
    {
        return match ($status) {
            400 => 'Requisição inválida',
            401 => 'Não autenticado',
            403 => 'Acesso negado',
            404 => 'Recurso não encontrado',
            405 => 'Método não permitido',
            409 => 'Conflito',
            422 => 'Dados inválidos',
            429 => 'Limite de requisições excedido',
            500 => 'Erro interno',
            default => $status >= 500 ? 'Erro do servidor' : 'Erro na requisição',
        };
    }
}
