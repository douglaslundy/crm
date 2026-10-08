<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use Tests\Fixtures\ContratoOpenApi;
use Tests\TestCase;

class OpenApiTest extends TestCase
{
    /** @return list<string> */
    private function rotasDaV1(): array
    {
        $rotas = [];
        foreach (Route::getRoutes() as $rota) {
            if (! str_starts_with($rota->uri(), 'api/v1/')) {
                continue;
            }
            foreach ($rota->methods() as $metodo) {
                if ($metodo !== 'HEAD') {
                    $rotas[] = $metodo.' /'.$rota->uri();
                }
            }
        }

        return $rotas;
    }

    public function test_toda_rota_v1_esta_documentada_e_vice_versa(): void
    {
        $codigo = $this->rotasDaV1();
        $contrato = array_keys(ContratoOpenApi::operacoes());
        sort($codigo);
        sort($contrato);

        $this->assertSame([], array_values(array_diff($codigo, $contrato)), 'Rotas sem documentação');
        $this->assertSame([], array_values(array_diff($contrato, $codigo)), 'Documentadas que não existem');
    }

    public function test_formato_do_documento(): void
    {
        $doc = ContratoOpenApi::carregar();
        $this->assertStringStartsWith('3.1', (string) $doc['openapi']);

        $ids = [];
        foreach (ContratoOpenApi::operacoes() as $chave => $o) {
            $op = $o['op'];
            $this->assertNotEmpty($op['operationId'] ?? null, "{$chave} sem operationId");
            $this->assertArrayNotHasKey($op['operationId'], $ids, "{$chave}: operationId repetido");
            $ids[$op['operationId']] = true;
            $this->assertArrayHasKey('401', $op['responses'], "{$chave} sem resposta 401");
            $this->assertNotEmpty($op['summary'] ?? null, "{$chave} sem summary");

            $declarados = [];
            foreach ($op['parameters'] ?? [] as $p) {
                if (isset($p['$ref'])) {
                    $p = $doc['components']['parameters'][basename((string) $p['$ref'])] ?? [];
                }
                $declarados[] = $p['name'] ?? '';
            }
            preg_match_all('/\{(\w+)\}/', $o['caminho'], $m);
            foreach ($m[1] as $param) {
                $this->assertContains($param, $declarados, "{$chave}: parâmetro {$param} não declarado");
            }
        }
    }

    public function test_todo_ref_interno_resolve(): void
    {
        $doc = ContratoOpenApi::carregar();
        preg_match_all('/"\$ref":"#\\\\\/([^"]+)"/', (string) json_encode($doc), $m);
        $this->assertNotEmpty($m[1]);
        foreach (array_unique($m[1]) as $caminho) {
            $no = $doc;
            foreach (explode('/', str_replace('\/', '/', $caminho)) as $parte) {
                $this->assertIsArray($no, "\$ref quebrado: #/{$caminho}");
                $this->assertArrayHasKey($parte, $no, "\$ref quebrado: #/{$caminho}");
                $no = $no[$parte];
            }
        }
    }
}
