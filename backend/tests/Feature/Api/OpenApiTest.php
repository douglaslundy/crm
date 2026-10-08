<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Modules\Shared\Domain\Cnpj;
use App\Modules\Shared\Domain\Cpf;
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

    public function test_documentos_de_exemplo_usam_cpf_cnpj_validos(): void
    {
        $raiz = dirname(base_path()).'/docs/api/';
        foreach (['openapi.yaml', 'guia-do-integrador.md'] as $arquivo) {
            preg_match_all('/cpf_cnpj"?: "([^"]+)"/', (string) file_get_contents($raiz.$arquivo), $m);
            foreach ($m[1] as $documento) {
                $valido = Cnpj::tentar($documento) !== null || Cpf::tentar($documento) !== null;
                $this->assertTrue($valido, "{$arquivo}: exemplo com CPF/CNPJ inválido ({$documento}); o servidor o recusaria");
            }
        }
    }

    public function test_servidor_documentado_inclui_o_prefixo_da_v1(): void
    {
        $this->assertStringEndsWith('/api/v1', (string) ContratoOpenApi::carregar()['servers'][0]['url']);
    }

    public function test_toda_operacao_declara_a_resposta_403_de_plano_e_assinatura(): void
    {
        foreach (ContratoOpenApi::operacoes() as $chave => $o) {
            $this->assertArrayHasKey('403', $o['op']['responses'], "{$chave} sem resposta 403");
        }
    }

    public function test_schemas_de_resposta_de_webhook_nao_aceitam_eventos_nulos(): void
    {
        $schemas = ContratoOpenApi::carregar()['components']['schemas'];
        $this->assertSame('array', $schemas['Webhook']['properties']['eventos']['type']);
        $this->assertSame('array', $schemas['WebhookComSegredo']['properties']['eventos']['type']);
    }
}
