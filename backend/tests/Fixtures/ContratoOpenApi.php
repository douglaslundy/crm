<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use JsonSchema\Constraints\Factory;
use JsonSchema\SchemaStorage;
use JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;

/** Lê `docs/api/openapi.yaml` e valida respostas reais contra os schemas de `components.schemas`. */
final class ContratoOpenApi
{
    /** @var array<string, mixed>|null */
    private static ?array $documento = null;

    /** @return array<string, mixed> */
    public static function carregar(): array
    {
        return self::$documento ??= Yaml::parseFile(dirname(base_path()).'/docs/api/openapi.yaml');
    }

    /** @return array<string, array{metodo: string, caminho: string, op: array<string, mixed>}> */
    public static function operacoes(): array
    {
        $saida = [];
        foreach (self::carregar()['paths'] ?? [] as $caminho => $item) {
            foreach ($item as $metodo => $op) {
                if (in_array($metodo, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $saida[strtoupper($metodo).' /api/v1'.$caminho] = ['metodo' => strtoupper($metodo), 'caminho' => '/api/v1'.$caminho, 'op' => $op];
                }
            }
        }

        return $saida;
    }

    /** @return list<string> erros; vazia quando válido */
    public static function validar(string $schema, mixed $dados): array
    {
        // Só os schemas: exemplos em `paths` podem ter chaves `id` que o validador leria como identificador de schema.
        $documento = json_decode((string) json_encode(['components' => ['schemas' => self::carregar()['components']['schemas']]]));
        $armazem = new SchemaStorage;
        $armazem->addSchema('file://openapi', $documento);
        $validador = new Validator(new Factory($armazem));
        $objeto = json_decode((string) json_encode($dados));
        $validador->validate($objeto, (object) ['$ref' => "file://openapi#/components/schemas/{$schema}"]);

        return array_map(fn (array $e): string => "{$e['property']}: {$e['message']}", $validador->getErrors());
    }
}
