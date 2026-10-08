<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Application\Webhooks\ResolvedorDeDns;
use App\Modules\Fiscal\Application\Webhooks\UrlDeWebhookSegura;
use App\Modules\Fiscal\Domain\Exceptions\WebhookUrlInvalidaException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UrlDeWebhookSeguraTest extends TestCase
{
    /** @var array<string, list<string>> */
    private array $dns = ['exemplo.test' => ['93.184.216.34']];

    private function validador(): UrlDeWebhookSegura
    {
        $dns = &$this->dns;

        return new UrlDeWebhookSegura(new class($dns) implements ResolvedorDeDns
        {
            /** @param array<string, list<string>> $dns */
            public function __construct(private array &$dns) {}

            public function resolver(string $host): array
            {
                return $this->dns[$host] ?? [];
            }
        });
    }

    private function emProducao(): void
    {
        $this->app['env'] = 'production';
    }

    public function test_url_https_com_ip_publico_passa_e_devolve_os_ips(): void
    {
        $this->emProducao();
        $this->dns['exemplo.test'] = ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'];

        $v = $this->validador()->validar('https://exemplo.test/hook?x=1');

        $this->assertSame('https://exemplo.test/hook?x=1', $v->url);
        $this->assertSame('exemplo.test', $v->host);
        $this->assertSame(443, $v->porta);
        $this->assertSame(['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'], $v->ips);
    }

    public function test_porta_explicita_e_ip_publico_literal(): void
    {
        $this->emProducao();

        $v = $this->validador()->validar('https://8.8.8.8:8443/x');

        $this->assertSame(8443, $v->porta);
        $this->assertSame(['8.8.8.8'], $v->ips);
    }

    /** @return array<string, array{string}> */
    public static function urlsRecusadas(): array
    {
        $casos = [
            'http em producao' => 'http://exemplo.test/x',
            'credenciais' => 'https://usuario:senha@exemplo.test/x',
            'so usuario' => 'https://usuario@exemplo.test/x',
            'ftp' => 'ftp://exemplo.test/x',
            'privado 10' => 'https://10.0.0.1/x',
            'privado 172' => 'https://172.16.0.1/x',
            'privado 192' => 'https://192.168.1.1/x',
            'loopback' => 'https://127.0.0.1/x',
            'loopback 127.1' => 'https://127.1/x',
            'metadados' => 'https://169.254.169.254/latest/meta-data',
            'cgnat' => 'https://100.64.0.1/x',
            'zero' => 'https://0.0.0.0/x',
            'ipv6 loopback' => 'https://[::1]/x',
            'ipv6 nao especificado' => 'https://[::]/x',
            'ipv6 ula' => 'https://[fd00::1]/x',
            'ipv6 metadados aws' => 'https://[fd00:ec2::254]/x',
            'ipv6 link-local' => 'https://[fe80::1]/x',
            'ipv4 mapeado' => 'https://[::ffff:127.0.0.1]/x',
            'ipv4 mapeado hex' => 'https://[::ffff:7f00:1]/x',
            'nat64' => 'https://[64:ff9b::7f00:1]/x',
            'decimal' => 'https://2130706433/x',
            'hexadecimal' => 'https://0x7f000001/x',
            'octal' => 'https://017700000001/x',
            'octal pontuado' => 'https://0177.0.0.1/x',
            'hex pontuado' => 'https://0x7f.0.0.1/x',
            'interno resolvido' => 'https://interno.test/x',
            'um interno entre varios' => 'https://misto.test/x',
            'rebinding v6' => 'https://interno6.test/x',
            'nao resolve' => 'https://nao-existe.test/x',
            'url longa' => 'https://exemplo.test/'.str_repeat('a', 500),
            'porta zero' => 'https://exemplo.test:0/x',
            'porta alta' => 'https://exemplo.test:70000/x',
            'texto' => 'isto nao e uma url',
            'vazio' => '',
            'sem host' => 'https:///x',
            'espaco' => 'https://exemplo.test/a b',
            'barra invertida' => 'https://exemplo.test\\@10.0.0.1/x',
        ];

        return array_map(fn (string $url): array => [$url], $casos);
    }

    #[DataProvider('urlsRecusadas')]
    public function test_urls_perigosas_ou_invalidas_sao_recusadas(string $url): void
    {
        $this->emProducao();
        $this->dns += [
            'interno.test' => ['10.1.2.3'],
            'misto.test' => ['93.184.216.34', '192.168.0.9'],
            'interno6.test' => ['fd12::1'],
        ];

        try {
            $this->validador()->validar($url);
            $this->fail("Deveria recusar: {$url}");
        } catch (WebhookUrlInvalidaException $e) {
            $this->assertSame('WEBHOOK_URL_INVALIDA', $e->codigo());
            $this->assertSame(422, $e->status());
            $this->assertSame('url', $e->extras()['errors'][0]['campo']);
            $this->assertNotSame('', $e->extras()['errors'][0]['mensagem']);
        }
    }

    public function test_em_testing_http_e_localhost_sao_aceitos(): void
    {
        $this->assertTrue($this->app->environment('testing'));

        $this->assertSame('localhost', $this->validador()->validar('http://localhost:8080/x')->host);
        $this->assertSame(['127.0.0.1'], $this->validador()->validar('http://127.0.0.1/x')->ips);
        $this->assertSame(['93.184.216.34'], $this->validador()->validar('http://exemplo.test/x')->ips);
    }

    public function test_em_testing_ip_interno_que_nao_e_loopback_continua_recusado(): void
    {
        $this->expectException(WebhookUrlInvalidaException::class);
        $this->validador()->validar('http://169.254.169.254/x');
    }

    public function test_em_producao_localhost_e_recusado(): void
    {
        $this->emProducao();
        $this->dns['localhost'] = ['127.0.0.1'];

        $this->expectException(WebhookUrlInvalidaException::class);
        $this->validador()->validar('https://localhost/x');
    }
}
