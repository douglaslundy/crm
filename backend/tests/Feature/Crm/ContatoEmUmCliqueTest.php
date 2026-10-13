<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Application\Actions\CriarNotaDoCliente;
use App\Modules\Crm\Application\Actions\RegistrarContato;
use App\Modules\Crm\Application\LinkDeContato;
use App\Modules\Crm\Domain\Enums\TipoDeAtividade;
use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Crm\Domain\Models\ConfiguracaoDoCrm;
use App\Modules\Crm\Domain\Models\RegistroDaTimeline;
use App\Modules\Customers\Domain\Models\Cliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ComCrm;
use Tests\TestCase;

class ContatoEmUmCliqueTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
        ConfiguracaoDoCrm::create(['vendedor_ve_so_os_seus' => true]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function telefonesValidos(): array
    {
        return [
            'celular com máscara' => ['(11) 99999-0000', 'https://wa.me/5511999990000'],
            'fixo com DDD' => ['1133334444', 'https://wa.me/551133334444'],
            'com +55' => ['+55 11 99999-0000', 'https://wa.me/5511999990000'],
            'com 55 e sem sinal' => ['5511999990000', 'https://wa.me/5511999990000'],
        ];
    }

    #[DataProvider('telefonesValidos')]
    public function test_normaliza_telefone(string $entrada, string $esperado): void
    {
        $this->assertSame($esperado, app(LinkDeContato::class)->whatsapp($entrada));
    }

    /** @return array<string, array{0: ?string}> */
    public static function telefonesInvalidos(): array
    {
        return ['nulo' => [null], 'vazio' => [''], 'sem DDD' => ['99999-0000'], 'oito dígitos' => ['33334444'], 'letras' => ['abc']];
    }

    #[DataProvider('telefonesInvalidos')]
    public function test_telefone_invalido_bloqueia_com_mensagem(?string $entrada): void
    {
        try {
            app(LinkDeContato::class)->whatsapp($entrada);
            $this->fail('Deveria bloquear.');
        } catch (RegraDoCrmException $e) {
            $this->assertSame('TELEFONE_INVALIDO', $e->codigo());
            $this->assertStringContainsString('DDD', $e->getMessage());
        }
    }

    public function test_email_valido_e_invalido(): void
    {
        $this->assertSame('mailto:ana@empresa.com', app(LinkDeContato::class)->email(' ana@empresa.com '));

        $this->expectException(RegraDoCrmException::class);
        app(LinkDeContato::class)->email('sem-arroba');
    }

    public function test_whatsapp_cria_atividade_concluida_e_registro_na_timeline(): void
    {
        $cliente = Cliente::factory()->create(['nome' => 'Ana', 'telefone' => '(11) 99999-0000']);

        $r = app(RegistrarContato::class)->executar($this->admin, 'WHATSAPP', $cliente->id, null);

        $this->assertSame('https://wa.me/5511999990000', $r['url']);
        $this->assertSame(TipoDeAtividade::Whatsapp, $r['atividade']->tipo);
        $this->assertTrue($r['atividade']->estaConcluida());
        $this->assertSame('WhatsApp para Ana', $r['atividade']->titulo);
        $this->assertSame(1, RegistroDaTimeline::query()->where('tipo', TipoDeRegistro::AtividadeConcluida)->count());
    }

    public function test_telefone_invalido_nao_cria_nada(): void
    {
        $cliente = Cliente::factory()->create(['telefone' => null]);

        try {
            app(RegistrarContato::class)->executar($this->admin, 'WHATSAPP', $cliente->id, null);
            $this->fail('Deveria bloquear.');
        } catch (RegraDoCrmException) {
            $this->assertSame(0, Atividade::query()->count());
            $this->assertSame(0, RegistroDaTimeline::query()->count());
        }
    }

    public function test_cada_clique_cria_uma_atividade(): void
    {
        $cliente = Cliente::factory()->create(['telefone' => '11999990000']);

        app(RegistrarContato::class)->executar($this->admin, 'WHATSAPP', $cliente->id, null);
        app(RegistrarContato::class)->executar($this->admin, 'WHATSAPP', $cliente->id, null);

        $this->assertSame(2, Atividade::query()->count());
    }

    public function test_canal_desconhecido_e_recusado(): void
    {
        $cliente = Cliente::factory()->create();

        $this->expectException(RegraDoCrmException::class);
        app(RegistrarContato::class)->executar($this->admin, 'TELEGRAM', $cliente->id, null);
    }

    public function test_nota_do_cliente(): void
    {
        $cliente = Cliente::factory()->create();

        $r = app(CriarNotaDoCliente::class)->executar($this->admin, $cliente->id, '  Prefere contato à tarde.  ');

        $this->assertSame(TipoDeRegistro::Nota, $r->tipo);
        $this->assertSame('Prefere contato à tarde.', $r->texto);
        $this->assertSame($this->admin->id, $r->autor_id);
    }

    public function test_nota_vazia_e_recusada(): void
    {
        $cliente = Cliente::factory()->create();

        $this->expectException(RegraDoCrmException::class);
        app(CriarNotaDoCliente::class)->executar($this->admin, $cliente->id, '   ');
    }
}
