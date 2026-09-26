<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Enums;

enum Recurso: string
{
    case Usuarios = 'USUARIOS';
    case Clientes = 'CLIENTES';
    case Produtos = 'PRODUTOS';
    case Servicos = 'SERVICOS';
    case DocumentosMes = 'DOCUMENTOS_MES';
    case ApiRequisicoesMin = 'API_REQUISICOES_MIN';

    /** Completa a frase "Seu plano permite até N ...". */
    public function descricaoDoLimite(): string
    {
        return match ($this) {
            self::Usuarios => 'usuários ativos',
            self::Clientes => 'clientes',
            self::Produtos => 'produtos',
            self::Servicos => 'serviços',
            self::DocumentosMes => 'documentos por mês',
            self::ApiRequisicoesMin => 'requisições por minuto',
        };
    }

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
